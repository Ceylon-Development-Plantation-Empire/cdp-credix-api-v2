<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\BorrowerType;
use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Services\Accounting\LoanPostingService;
use App\Services\Customers\CreditScoreService;
use App\Services\Recovery\RecoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The nightly run (design section 10), for one business date, loan by loan:
 *   1. late charges are raised (penalty rule of the product, else settings), and the monthly
 *      safekeeping fee of pledged gold when a new month starts;
 *   2. installment statuses, days past due, arrears and risk stage are rebuilt;
 *   3. a loan that became non-performing has its interest suspended (or released once its
 *      arrears are cleared), then interest / profit that fell due is posted to the general ledger;
 *   4. recovery: notice at 90 DPD, agent stage at 180 DPD (settings);
 *   5. the borrowers' credit scores are recomputed.
 *
 * Every step is idempotent, so running the same date twice changes nothing. Each loan is done in
 * its own transaction: one bad loan is logged and skipped instead of stopping the whole run.
 * A cache lock stops two runs overlapping.
 */
class EndOfDayService
{
    public function __construct(
        protected PenaltyService $penalties,
        protected LoanBalanceService $balances,
        protected RecoveryService $recovery,
        protected CreditScoreService $creditScores,
        protected LoanPostingService $posting,
        protected SafekeepingFeeService $safekeeping,
    ) {}

    /**
     * @return array{loans: int, penalties: int, failed: int}
     */
    public function run(CarbonImmutable $businessDate): array
    {
        $lock = Cache::lock('eod:run', 3600);

        if (! $lock->get()) {
            throw new RuntimeException('The end-of-day run is already in progress.');
        }

        $result = ['loans' => 0, 'penalties' => 0, 'failed' => 0];

        try {
            Loan::query()
                ->withoutGlobalScope('branch')
                ->where('status', LoanStatus::Active->value)
                // Group facilities hold no schedule; their member loans are processed and roll up.
                ->where(fn ($q) => $q->whereNotNull('parent_id')->orWhere('borrower_type', '!=', BorrowerType::Group->value))
                ->chunkById(200, function ($loans) use ($businessDate, &$result) {
                    foreach ($loans as $loan) {
                        try {
                            DB::transaction(function () use ($loan, $businessDate, &$result) {
                                $loan = Loan::withoutGlobalScope('branch')->whereKey($loan->id)->lockForUpdate()->first();
                                $result['penalties'] += $this->penalties->apply($loan, $businessDate);
                                $this->safekeeping->apply($loan, $businessDate);
                                $this->balances->refresh($loan, $businessDate);
                                $this->posting->evaluateSuspension($loan, $businessDate);
                                $this->posting->recogniseDueProfit($loan, $businessDate);
                                $this->recovery->evaluate($loan, $businessDate);

                                foreach ($loan->borrowingParties()->with('customer')->get() as $party) {
                                    $this->creditScores->recompute($party->customer, $businessDate);
                                }
                            }, attempts: 3);
                            $result['loans']++;
                        } catch (\Throwable $th) {
                            $result['failed']++;
                            Log::error("EOD failed for loan {$loan->id}", ['exception' => $th]);
                        }
                    }
                });
        } finally {
            $lock->release();
        }

        return $result;
    }
}

<?php

namespace App\Services\Accounting;

use App\Enums\GlJournalSource;
use App\Enums\GlJournalStatus;
use App\Enums\LoanStatus;
use App\Finance\Support\Money;
use App\Models\GlAccount;
use App\Models\GlJournal;
use App\Models\GlJournalLine;
use App\Models\Loan;
use App\Models\Setting;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Provision for loan losses, loan by loan, at a reporting date (month end).
 *
 * Required = outstanding principal × the rate for the loan's days past due (provision.rates).
 * Only the difference to what the loan already holds is posted: a top-up debits the provision
 * expense, a release credits it back. A closed loan needs none, so whatever it still holds is
 * released (a write-off has already used what it needed). Collateral is not deducted yet.
 *
 * Running the same date twice posts nothing new (one journal per loan per date).
 */
class ProvisionService
{
    /**
     * Used when the provision.rates setting is missing (same as the seeded default).
     */
    public const DEFAULT_RATES = [
        ['min_dpd' => 0, 'percent' => '1'],
        ['min_dpd' => 31, 'percent' => '5'],
        ['min_dpd' => 91, 'percent' => '20'],
        ['min_dpd' => 181, 'percent' => '50'],
        ['min_dpd' => 361, 'percent' => '100'],
    ];

    public function __construct(
        protected GeneralLedgerService $gl,
    ) {}

    /**
     * @return array{loans: int, journals: int, failed: int, total_required: string}
     */
    public function run(CarbonImmutable $asOf): array
    {
        $rates = collect(Setting::value('provision.rates', self::DEFAULT_RATES))->sortByDesc('min_dpd')->values();
        $held = $this->heldByLoan();
        $result = ['loans' => 0, 'journals' => 0, 'failed' => 0, 'total_required' => Money::zero()];

        Loan::query()
            ->withoutGlobalScope('branch')
            ->accounts()
            ->where(fn ($q) => $q->where('status', LoanStatus::Active->value)->orWhereIn('id', $held->keys()))
            ->chunkById(200, function ($loans) use ($asOf, $rates, $held, &$result) {
                foreach ($loans as $loan) {
                    try {
                        $required = $loan->status === LoanStatus::Active
                            ? Money::percentOf($loan->outstanding_principal, $this->rateFor($loan->days_past_due, $rates))
                            : Money::zero();

                        $result['total_required'] = $result['total_required']->plus($required);
                        $result['journals'] += $this->post($loan, $required, $held[$loan->id] ?? Money::zero(), $asOf) ? 1 : 0;
                        $result['loans']++;
                    } catch (\Throwable $th) {
                        $result['failed']++;
                        Log::error("Provisioning failed for loan {$loan->id}", ['exception' => $th]);
                    }
                }
            });

        $result['total_required'] = (string) $result['total_required'];

        return $result;
    }

    protected function post(Loan $loan, BigDecimal $required, BigDecimal $held, CarbonImmutable $asOf): bool
    {
        $delta = $required->minus($held);
        $key = "provision:{$loan->id}:{$asOf->toDateString()}";

        if ($delta->isZero() || GlJournal::withoutGlobalScope('branch')->where('source_key', $key)->exists()) {
            return false;
        }

        DB::transaction(fn () => $this->gl->write(GlJournalSource::Provision, $asOf, ($delta->isPositive() ? 'Provision top-up' : 'Provision release')." at {$loan->days_past_due} days past due - {$loan->loan_no}", $this->gl->linesFrom([
            'provision-expense' => $delta,
            'loan-loss-provision' => $delta->negated(),
        ]), [
            'source_key' => $key,
            'branch_id' => $loan->branch_id,
            'loan_id' => $loan->id,
        ]));

        return true;
    }

    /**
     * @param  Collection<int, array{min_dpd: int, percent: string}>  $rates  highest band first
     */
    protected function rateFor(int $dpd, $rates): string
    {
        return (string) ($rates->first(fn (array $band) => $dpd >= (int) $band['min_dpd'])['percent'] ?? '0');
    }

    /**
     * Provision each loan holds now (credit balance, as a positive amount), only loans holding some.
     *
     * @return Collection<int, BigDecimal>
     */
    protected function heldByLoan()
    {
        return GlJournalLine::query()
            ->join('gl_journals', 'gl_journals.id', '=', 'gl_journal_lines.gl_journal_id')
            ->where('gl_journals.status', GlJournalStatus::Posted->value)
            ->where('gl_journal_lines.gl_account_id', GlAccount::where('slug', 'loan-loss-provision')->value('id'))
            ->whereNotNull('gl_journals.loan_id')
            ->groupBy('gl_journals.loan_id')
            ->get(['gl_journals.loan_id', DB::raw('SUM(gl_journal_lines.credit) - SUM(gl_journal_lines.debit) as held')])
            ->mapWithKeys(fn ($row) => [$row->loan_id => Money::of((string) $row->held)])
            ->filter(fn (BigDecimal $held) => ! $held->isZero());
    }
}

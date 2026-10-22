<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\GlJournalStatus;
use App\Enums\InstallmentStatus;
use App\Finance\Support\Money;
use App\Models\Loan;
use App\Models\LoanInstallment;
use Illuminate\Support\Facades\DB;

/**
 * Proves the books are consistent (php artisan ledger:reconcile, nightly):
 *
 * 1. every posted journal balances: SUM(debit) = SUM(credit) = journal total;
 * 2. every installment's paid columns equal the SUM of its allocation lines
 *    (principal, profit, charge; penalty paid + waived = penalty allocations);
 * 3. every loan's balance cache equals what its schedule in force still owes;
 * 4. the GL control accounts equal the loan sub-ledger (GlReportService::reconciliation);
 * 5. no orphans: every ledger transaction has its GL journal.
 *
 * Read-only. All sums are exact decimals (SQL SUM on DECIMAL columns, compared with BigDecimal).
 */
class LedgerReconciliationService
{
    public function __construct(
        protected GlReportService $reports,
    ) {}

    /**
     * Run every check.
     *
     * @return array{reconciled: bool, checks: array<string, array{ok: bool, problems: list<string>}>}
     */
    public function run(): array
    {
        $checks = [
            'journals_balance' => $this->journalsBalance(),
            'allocations_match_installments' => $this->allocationsMatchInstallments(),
            'loan_cache_matches_schedule' => $this->loanCacheMatchesSchedule(),
            'gl_matches_sub_ledger' => $this->glMatchesSubLedger(),
            'transactions_have_journals' => $this->transactionsHaveJournals(),
        ];

        return [
            'reconciled' => collect($checks)->every(fn (array $c) => $c['ok']),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{ok: bool, problems: list<string>}
     */
    protected function journalsBalance(): array
    {
        $rows = DB::table('gl_journals')
            ->join('gl_journal_lines', 'gl_journal_lines.gl_journal_id', '=', 'gl_journals.id')
            ->where('gl_journals.status', GlJournalStatus::Posted->value)
            ->groupBy('gl_journals.id', 'gl_journals.journal_no', 'gl_journals.total')
            ->get(['gl_journals.journal_no', 'gl_journals.total', DB::raw('SUM(gl_journal_lines.debit) as debit'), DB::raw('SUM(gl_journal_lines.credit) as credit')]);

        $problems = [];
        foreach ($rows as $row) {
            $debit = Money::of((string) $row->debit);
            $credit = Money::of((string) $row->credit);

            if (! $debit->isEqualTo($credit) || ! $debit->isEqualTo(Money::of((string) $row->total))) {
                $problems[] = "Journal {$row->journal_no}: debits {$debit}, credits {$credit}, total {$row->total}";
            }
        }

        return ['ok' => $problems === [], 'problems' => $problems];
    }

    /**
     * @return array{ok: bool, problems: list<string>}
     */
    protected function allocationsMatchInstallments(): array
    {
        $allocated = DB::table('loan_allocations')
            ->whereNotNull('loan_installment_id')
            ->groupBy('loan_installment_id', 'component')
            ->get(['loan_installment_id', 'component', DB::raw('SUM(amount) as total')])
            ->groupBy('loan_installment_id');

        $problems = [];

        foreach (LoanInstallment::query()->cursor() as $installment) {
            $sums = $allocated->get($installment->id, collect())->mapWithKeys(fn ($r) => [$r->component => Money::of((string) $r->total)]);
            $sum = fn (string $component) => $sums->get($component, Money::zero());

            $pairs = [
                'principal' => [Money::of($installment->principal_paid), $sum('principal')],
                'profit' => [Money::of($installment->profit_paid), $sum('profit')],
                'charge' => [Money::of($installment->charges_paid), $sum('charge')],
                // A waived penalty is allocated but booked as waived, not paid.
                'penalty' => [Money::of($installment->penalty_paid)->plus($installment->penalty_waived), $sum('penalty')],
            ];

            foreach ($pairs as $component => [$cached, $lines]) {
                if (! $cached->isEqualTo($lines)) {
                    $problems[] = "Installment {$installment->id} ({$component}): columns {$cached}, allocations {$lines}";
                }
            }
        }

        return ['ok' => $problems === [], 'problems' => $problems];
    }

    /**
     * @return array{ok: bool, problems: list<string>}
     */
    protected function loanCacheMatchesSchedule(): array
    {
        $problems = [];

        $owed = DB::table('loan_installments')
            ->where('status', '!=', InstallmentStatus::Rescheduled->value)
            ->groupBy('loan_id')
            ->get([
                'loan_id',
                DB::raw('SUM(principal_due - principal_paid) as principal'),
                DB::raw('SUM(profit_due - profit_paid) as profit'),
                DB::raw('SUM(charges_due - charges_paid) as charges'),
                DB::raw('SUM(penalty_due - penalty_paid - penalty_waived) as penalty'),
            ])->keyBy('loan_id');

        $loans = Loan::withoutGlobalScope('branch')->accounts()->whereNotNull('disbursed_at')
            ->get(['id', 'loan_no', 'outstanding_principal', 'outstanding_profit', 'outstanding_charges', 'outstanding_penalty']);

        foreach ($loans as $loan) {
            $row = $owed->get($loan->id);

            foreach (['principal', 'profit', 'charges', 'penalty'] as $part) {
                $schedule = Money::of((string) ($row->{$part} ?? 0));
                $cache = Money::of((string) $loan->{"outstanding_{$part}"});

                if (! $schedule->isEqualTo($cache)) {
                    $problems[] = "Loan {$loan->loan_no} ({$part}): cache {$cache}, schedule {$schedule}";
                }
            }
        }

        return ['ok' => $problems === [], 'problems' => $problems];
    }

    /**
     * @return array{ok: bool, problems: list<string>}
     */
    protected function glMatchesSubLedger(): array
    {
        $result = $this->reports->reconciliation(null);
        $problems = [];

        foreach ($result['accounts'] as $row) {
            if ($row['difference'] !== '0.00') {
                $problems[] = "{$row['account']['code']} {$row['account']['name']}: GL {$row['gl_balance']}, loans {$row['sub_ledger']}";
            }
        }

        return ['ok' => $problems === [], 'problems' => $problems];
    }

    /**
     * @return array{ok: bool, problems: list<string>}
     */
    protected function transactionsHaveJournals(): array
    {
        $orphans = DB::table('loan_transactions')
            ->whereNotExists(fn ($q) => $q->from('gl_journals')->whereColumn('gl_journals.loan_transaction_id', 'loan_transactions.id'))
            ->limit(100)
            ->pluck('txn_no');

        return [
            'ok' => $orphans->isEmpty(),
            'problems' => $orphans->map(fn ($no) => "Transaction {$no} has no GL journal")->all(),
        ];
    }
}

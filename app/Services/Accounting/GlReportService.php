<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\FinanceBasis;
use App\Enums\GlJournalStatus;
use App\Enums\InstallmentStatus;
use App\Finance\Support\Money;
use App\Models\GlAccount;
use App\Models\GlJournalLine;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Support\BranchAccess;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Trial balance, account statement, reconciliation to the loan sub-ledger and the journal export.
 *
 * Only posted journals count. Journal lines have no branch scope of their own, so the branch is
 * applied here: a branch user always gets their own branch, whatever they ask for.
 */
class GlReportService
{
    /**
     * @return array<string, mixed>
     */
    public function trialBalance(CarbonImmutable $asOf, ?int $branchId, bool $includeZero = false): array
    {
        $branchId = $this->branch($branchId);

        $sums = $this->postedLines($branchId)
            ->where('gl_journals.entry_date', '<', $asOf->addDay()->toDateString())
            ->groupBy('gl_journal_lines.gl_account_id')
            ->get(['gl_journal_lines.gl_account_id', DB::raw('SUM(gl_journal_lines.debit) as debit'), DB::raw('SUM(gl_journal_lines.credit) as credit')])
            ->keyBy('gl_account_id');

        $rows = [];
        $totalDebit = Money::zero();
        $totalCredit = Money::zero();

        foreach (GlAccount::orderBy('code')->get() as $account) {
            $sum = $sums[$account->id] ?? null;
            $balance = Money::of((string) ($sum->debit ?? 0))->minus(Money::of((string) ($sum->credit ?? 0)));

            if ($balance->isZero() && ! $includeZero && ! $sum) {
                continue;
            }

            $debit = $balance->isPositive() ? $balance : Money::zero();
            $credit = $balance->isNegative() ? $balance->negated() : Money::zero();
            $totalDebit = $totalDebit->plus($debit);
            $totalCredit = $totalCredit->plus($credit);

            $rows[] = [
                'account' => $this->accountSummary($account),
                'debit' => (string) $debit,
                'credit' => (string) $credit,
            ];
        }

        return [
            'as_of' => $asOf->toDateString(),
            'branch_id' => $branchId,
            'accounts' => $rows,
            'totals' => ['debit' => (string) $totalDebit, 'credit' => (string) $totalCredit],
            'balanced' => $totalDebit->isEqualTo($totalCredit),
        ];
    }

    /**
     * Movements of one account in a period, with the opening and closing balance. Balances are
     * in the account's normal direction (an asset's debit balance and an income's credit balance
     * are both positive).
     *
     * @return array<string, mixed>
     */
    public function statement(GlAccount $account, CarbonImmutable $from, CarbonImmutable $to, ?int $branchId, int $perPage): array
    {
        $branchId = $this->branch($branchId);
        $natural = fn (mixed $debit, mixed $credit) => $account->type->isDebitNormal()
            ? Money::of((string) ($debit ?? 0))->minus(Money::of((string) ($credit ?? 0)))
            : Money::of((string) ($credit ?? 0))->minus(Money::of((string) ($debit ?? 0)));

        $base = fn () => $this->postedLines($branchId)->where('gl_journal_lines.gl_account_id', $account->id);

        $before = $base()->where('gl_journals.entry_date', '<', $from->toDateString())
            ->first([DB::raw('SUM(gl_journal_lines.debit) as debit'), DB::raw('SUM(gl_journal_lines.credit) as credit')]);
        $during = $base()->tap(fn (Builder $q) => $this->period($q, $from, $to))
            ->first([DB::raw('SUM(gl_journal_lines.debit) as debit'), DB::raw('SUM(gl_journal_lines.credit) as credit')]);

        $opening = $natural($before->debit, $before->credit);

        /** @var LengthAwarePaginator $page */
        $page = $base()->tap(fn (Builder $q) => $this->period($q, $from, $to))
            ->leftJoin('loans', 'loans.id', '=', 'gl_journals.loan_id')
            ->orderBy('gl_journals.entry_date')
            ->orderBy('gl_journals.id')
            ->select([
                'gl_journals.public_id', 'gl_journals.journal_no', 'gl_journals.entry_date', 'gl_journals.value_date',
                'gl_journals.source', 'gl_journals.narration', 'loans.loan_no',
                'gl_journal_lines.debit', 'gl_journal_lines.credit', 'gl_journal_lines.memo',
            ])
            ->paginate($perPage);

        return [
            'account' => $this->accountSummary($account),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'branch_id' => $branchId,
            'opening_balance' => (string) $opening,
            'total_debit' => (string) Money::of((string) ($during->debit ?? 0)),
            'total_credit' => (string) Money::of((string) ($during->credit ?? 0)),
            'closing_balance' => (string) $opening->plus($natural($during->debit, $during->credit)),
            'items' => collect($page->items())->map(fn ($row) => [
                'journal_public_id' => $row->public_id,
                'journal_no' => $row->journal_no,
                'entry_date' => CarbonImmutable::parse($row->entry_date)->toDateString(),
                'value_date' => CarbonImmutable::parse($row->value_date)->toDateString(),
                'source' => $row->source,
                'narration' => $row->narration,
                'loan_no' => $row->loan_no,
                'debit' => (string) Money::of((string) $row->debit),
                'credit' => (string) Money::of((string) $row->credit),
                'memo' => $row->memo,
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    /**
     * The GL control accounts against the loan sub-ledger they summarise. Any difference means a
     * movement reached one side and not the other and must be investigated.
     *
     * @return array<string, mixed>
     */
    public function reconciliation(?int $branchId): array
    {
        $branchId = $this->branch($branchId);
        $balances = $this->postedLines($branchId)
            ->join('gl_accounts', 'gl_accounts.id', '=', 'gl_journal_lines.gl_account_id')
            ->whereNotNull('gl_accounts.slug')
            ->groupBy('gl_accounts.slug')
            ->get(['gl_accounts.slug', DB::raw('SUM(gl_journal_lines.debit) - SUM(gl_journal_lines.credit) as balance')])
            ->mapWithKeys(fn ($row) => [$row->slug => Money::of((string) $row->balance)]);

        $loans = fn () => Loan::query()->accounts()->when($branchId !== null, fn (Builder $q) => $q->where('loans.branch_id', $branchId));
        $sum = fn (Builder $q, string $column) => Money::of((string) ($q->sum($column) ?? 0));

        $rows = [];

        foreach (FinanceBasis::cases() as $basis) {
            $islamic = $basis === FinanceBasis::Islamic;
            $principalAccount = $islamic ? 'islamic-financing-receivable' : 'loans-receivable';
            $profitAccount = $islamic ? 'profit-receivable' : 'interest-receivable';

            $rows[] = $this->reconRow($principalAccount, $balances[$principalAccount] ?? Money::zero(),
                $sum($loans()->where('loans.finance_basis', $basis->value), 'loans.outstanding_principal'));
            $rows[] = $this->reconRow($profitAccount, $balances[$profitAccount] ?? Money::zero(),
                $this->recognisedUnpaidProfit($basis, $branchId));
        }

        $rows[] = $this->reconRow('charges-receivable', $balances['charges-receivable'] ?? Money::zero(), $sum($loans(), 'loans.outstanding_charges'));
        $rows[] = $this->reconRow('penalty-receivable', $balances['penalty-receivable'] ?? Money::zero(), $sum($loans(), 'loans.outstanding_penalty'));

        return [
            'branch_id' => $branchId,
            'as_at' => now()->toApiString(),
            'accounts' => $rows,
            'reconciled' => collect($rows)->every(fn (array $r) => $r['difference'] === '0.00'),
        ];
    }

    /**
     * Journal lines for the accounting package, one row per line, oldest first.
     *
     * @return Generator<int, list<string|null>>
     */
    public function exportRows(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): Generator
    {
        yield ['journal_no', 'entry_date', 'value_date', 'source', 'branch_code', 'loan_no', 'account_code', 'account_name', 'debit', 'credit', 'narration', 'memo'];

        $query = $this->postedLines($this->branch($branchId))
            ->tap(fn (Builder $q) => $this->period($q, $from, $to))
            ->join('gl_accounts', 'gl_accounts.id', '=', 'gl_journal_lines.gl_account_id')
            ->leftJoin('branches', 'branches.id', '=', 'gl_journals.branch_id')
            ->leftJoin('loans', 'loans.id', '=', 'gl_journals.loan_id')
            ->orderBy('gl_journals.entry_date')
            ->orderBy('gl_journals.id')
            ->orderBy('gl_journal_lines.id')
            ->select([
                'gl_journal_lines.id', 'gl_journals.journal_no', 'gl_journals.entry_date', 'gl_journals.value_date', 'gl_journals.source',
                'branches.code as branch_code', 'loans.loan_no', 'gl_accounts.code as account_code', 'gl_accounts.name as account_name',
                'gl_journal_lines.debit', 'gl_journal_lines.credit', 'gl_journals.narration', 'gl_journal_lines.memo',
            ]);

        foreach ($query->lazy(500) as $row) {
            yield array_map($this->csvSafe(...), [
                $row->journal_no,
                CarbonImmutable::parse($row->entry_date)->toDateString(),
                CarbonImmutable::parse($row->value_date)->toDateString(),
                $row->source,
                $row->branch_code,
                $row->loan_no,
                $row->account_code,
                $row->account_name,
                (string) Money::of((string) $row->debit),
                (string) Money::of((string) $row->credit),
                $row->narration,
                $row->memo,
            ]);
        }
    }

    /**
     * @return array{account: array<string, mixed>, gl_balance: string, sub_ledger: string, difference: string}
     */
    protected function reconRow(string $slug, BigDecimal $glDebitBalance, BigDecimal $subLedger): array
    {
        return [
            'account' => $this->accountSummary(GlAccount::where('slug', $slug)->firstOrFail()),
            'gl_balance' => (string) $glDebitBalance,
            'sub_ledger' => (string) $subLedger,
            'difference' => (string) $glDebitBalance->minus($subLedger),
        ];
    }

    /**
     * Interest / profit still unpaid on installments whose due-date journal has been posted.
     */
    protected function recognisedUnpaidProfit(FinanceBasis $basis, ?int $branchId): BigDecimal
    {
        $key = DB::getDriverName() === 'sqlite' ? "'due:' || loan_installments.id" : "CONCAT('due:', loan_installments.id)";

        $total = LoanInstallment::query()
            ->join('loans', 'loans.id', '=', 'loan_installments.loan_id')
            ->whereNull('loans.deleted_at')
            ->where('loan_installments.status', '!=', InstallmentStatus::Rescheduled->value)
            ->where('loans.finance_basis', $basis->value)
            ->when($branchId !== null, fn (Builder $q) => $q->where('loans.branch_id', $branchId))
            ->whereExists(fn ($q) => $q->from('gl_journals')->whereRaw("gl_journals.source_key = {$key}"))
            ->sum(DB::raw('loan_installments.profit_due - loan_installments.profit_paid'));

        return Money::of((string) ($total ?? 0));
    }

    /**
     * from ≤ entry date < the day after to: right for both a DATE column and a stored datetime.
     */
    protected function period(Builder $query, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $query->where('gl_journals.entry_date', '>=', $from->toDateString())
            ->where('gl_journals.entry_date', '<', $to->addDay()->toDateString());
    }

    protected function postedLines(?int $branchId): Builder
    {
        return GlJournalLine::query()
            ->join('gl_journals', 'gl_journals.id', '=', 'gl_journal_lines.gl_journal_id')
            ->where('gl_journals.status', GlJournalStatus::Posted->value)
            ->when($branchId !== null, fn (Builder $q) => $q->where('gl_journals.branch_id', $branchId));
    }

    /**
     * A branch user is held to their own branch (0 when they have none, so they see nothing).
     */
    protected function branch(?int $requested): ?int
    {
        return BranchAccess::restrictedBranchId() ?? $requested;
    }

    /**
     * @return array<string, mixed>
     */
    protected function accountSummary(GlAccount $account): array
    {
        return ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type->value];
    }

    /**
     * A cell starting with = + - @ would run as a formula when the file is opened in a spreadsheet.
     */
    protected function csvSafe(?string $value): ?string
    {
        return $value !== null && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'{$value}" : $value;
    }
}

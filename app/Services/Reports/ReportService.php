<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\ApprovalStatus;
use App\Enums\LoanStatus;
use App\Enums\TransactionType;
use App\Finance\Support\Money;
use App\Models\ApprovalRequest;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\RecoveryCase;
use App\Support\BusinessDate;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only figures for management. Every query goes through the models, so the branch scope
 * applies: a branch manager sees their branch, head office sees all (optionally one branch).
 *
 * Money is added up on loan accounts only (Loan::accounts()), never on group facilities, so a
 * group loan is not counted twice. Amounts are returned as strings with 2 decimals.
 *
 * PAR (portfolio at risk) = outstanding principal of loans more than N days past due ÷ total
 * outstanding principal.
 */
class ReportService
{
    /**
     * @param  array{branch_id?: int|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function portfolio(array $filters): array
    {
        return $this->activeAccounts($filters)
            ->join('products', 'products.id', '=', 'loans.product_id')
            ->groupBy('products.slug', 'products.name')
            ->orderBy('products.name')
            ->get([
                'products.slug',
                'products.name',
                DB::raw('COUNT(*) as loans'),
                DB::raw('SUM(loans.outstanding_principal) as outstanding_principal'),
                DB::raw('SUM(loans.outstanding_profit) as outstanding_profit'),
                DB::raw('SUM(loans.arrears_amount) as arrears'),
                DB::raw('SUM(CASE WHEN loans.days_past_due > 30 THEN loans.outstanding_principal ELSE 0 END) as par30_principal'),
                DB::raw('SUM(CASE WHEN loans.days_past_due > 90 THEN loans.outstanding_principal ELSE 0 END) as par90_principal'),
            ])
            ->map(fn ($row) => [
                'product' => ['slug' => $row->slug, 'name' => $row->name],
                'loans' => (int) $row->loans,
                'outstanding_principal' => $this->money($row->outstanding_principal),
                'outstanding_profit' => $this->money($row->outstanding_profit),
                'arrears' => $this->money($row->arrears),
                'par30_percent' => $this->percent($row->par30_principal, $row->outstanding_principal),
                'par90_percent' => $this->percent($row->par90_principal, $row->outstanding_principal),
            ])->all();
    }

    /**
     * Arrears aging buckets by days past due.
     *
     * @param  array{branch_id?: int|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function arrearsAging(array $filters): array
    {
        $buckets = ['current' => [0, 0], '1-30' => [1, 30], '31-60' => [31, 60], '61-90' => [61, 90], '91-180' => [91, 180], '180+' => [181, 65535]];

        return collect($buckets)->map(function (array $range, string $bucket) use ($filters) {
            $row = $this->activeAccounts($filters)
                ->whereBetween('loans.days_past_due', $range)
                ->first([
                    DB::raw('COUNT(*) as loans'),
                    DB::raw('SUM(loans.outstanding_principal) as outstanding_principal'),
                    DB::raw('SUM(loans.arrears_amount) as arrears'),
                ]);

            return [
                'bucket' => $bucket,
                'loans' => (int) $row->loans,
                'outstanding_principal' => $this->money($row->outstanding_principal),
                'arrears' => $this->money($row->arrears),
            ];
        })->values()->all();
    }

    /**
     * Money received in a period, by channel. Reversals in the period are taken off.
     *
     * @param  array{from: string, to: string, branch_id?: int|null}  $filters
     * @return array<string, mixed>
     */
    public function collections(array $filters): array
    {
        $rows = $this->transactions($filters)
            ->whereIn('type', [TransactionType::Repayment->value, TransactionType::EarlySettlement->value, TransactionType::Reversal->value])
            ->groupBy('channel', 'type')
            ->get(['channel', 'type', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as amount')]);

        $byChannel = $rows->groupBy(fn ($r) => $r->channel->value)->map(function ($group, $channel) {
            $received = Money::sum($group->where('type', '!=', TransactionType::Reversal)->pluck('amount')->map(fn ($v) => (string) $v));
            $reversed = Money::sum($group->where('type', TransactionType::Reversal)->pluck('amount')->map(fn ($v) => (string) $v));

            return ['channel' => $channel, 'received' => (string) $received, 'reversed' => (string) $reversed, 'net' => (string) $received->minus($reversed)];
        })->values();

        return [
            'from' => $filters['from'],
            'to' => $filters['to'],
            'by_channel' => $byChannel->all(),
            'net_total' => (string) Money::sum($byChannel->pluck('net')),
        ];
    }

    /**
     * @param  array{from: string, to: string, branch_id?: int|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function disbursements(array $filters): array
    {
        return $this->accounts($filters)
            ->whereBetween('loans.disbursed_at', [$this->start($filters['from']), $this->end($filters['to'])])
            ->join('products', 'products.id', '=', 'loans.product_id')
            ->groupBy('products.slug', 'products.name')
            ->get([
                'products.slug',
                'products.name',
                DB::raw('COUNT(*) as loans'),
                DB::raw('SUM(loans.approved_amount) as amount'),
                DB::raw('SUM(loans.net_disbursement_amount) as paid_out'),
            ])
            ->map(fn ($row) => [
                'product' => ['slug' => $row->slug, 'name' => $row->name],
                'loans' => (int) $row->loans,
                'amount' => $this->money($row->amount),
                'paid_out' => $this->money($row->paid_out),
            ])->all();
    }

    /**
     * @param  array{from: string, to: string, branch_id?: int|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function branchPerformance(array $filters): array
    {
        $portfolio = $this->activeAccounts($filters)
            ->groupBy('loans.branch_id')
            ->get([
                'loans.branch_id',
                DB::raw('COUNT(*) as loans'),
                DB::raw('SUM(loans.outstanding_principal) as outstanding_principal'),
                DB::raw('SUM(loans.arrears_amount) as arrears'),
                DB::raw('SUM(CASE WHEN loans.days_past_due > 30 THEN loans.outstanding_principal ELSE 0 END) as par30_principal'),
            ])->keyBy('branch_id');

        $disbursed = $this->accounts($filters)
            ->whereBetween('loans.disbursed_at', [$this->start($filters['from']), $this->end($filters['to'])])
            ->groupBy('loans.branch_id');
        $disbursed = $this->sumBy($disbursed, 'loans.branch_id', 'loans.approved_amount');

        $collected = $this->transactions($filters)
            ->join('loans', 'loans.id', '=', 'loan_transactions.loan_id')
            ->whereIn('loan_transactions.type', [TransactionType::Repayment->value, TransactionType::EarlySettlement->value])
            ->groupBy('loans.branch_id');
        $collected = $this->sumBy($collected, 'loans.branch_id', 'loan_transactions.amount');

        $branchIds = $portfolio->keys()->merge($disbursed->keys())->merge($collected->keys())->unique();

        return Branch::whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name'])->map(fn (Branch $b) => [
            'branch' => ['id' => $b->id, 'name' => $b->name],
            'active_loans' => (int) ($portfolio[$b->id]->loans ?? 0),
            'outstanding_principal' => $this->money($portfolio[$b->id]->outstanding_principal ?? 0),
            'arrears' => $this->money($portfolio[$b->id]->arrears ?? 0),
            'par30_percent' => $this->percent($portfolio[$b->id]->par30_principal ?? 0, $portfolio[$b->id]->outstanding_principal ?? 0),
            'disbursed' => $this->money($disbursed[$b->id] ?? 0),
            'collected' => $this->money($collected[$b->id] ?? 0),
        ])->all();
    }

    /**
     * @param  array{branch_id?: int|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function recovery(array $filters): array
    {
        return RecoveryCase::query()
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->groupBy('stage', 'status')
            ->get(['stage', 'status', DB::raw('COUNT(*) as cases'), DB::raw('SUM(opened_arrears) as opened_arrears')])
            ->map(fn ($row) => [
                'stage' => $row->stage->value,
                'status' => $row->status->value,
                'cases' => (int) $row->cases,
                'opened_arrears' => $this->money($row->opened_arrears),
            ])->all();
    }

    /**
     * Money paid out and collected (net of reversals) per calendar month, oldest first, ending with
     * the current month. Each month reuses the disbursement and collection reports, so the chart
     * and the reports can never disagree.
     *
     * @param  array{branch_id?: int|null}  $filters
     * @return list<array{month: string, disbursed: string, collected: string}>
     */
    public function monthlyTrend(int $months, array $filters): array
    {
        $trend = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $start = BusinessDate::today()->startOfMonth()->subMonthsNoOverflow($i);
            $period = ['from' => $start->toDateString(), 'to' => $start->copy()->endOfMonth()->min(BusinessDate::today())->toDateString()] + $filters;

            $trend[] = [
                'month' => $start->format('Y-m'),
                'disbursed' => (string) Money::sum(array_column($this->disbursements($period), 'amount')),
                'collected' => $this->collections($period)['net_total'],
            ];
        }

        return $trend;
    }

    /**
     * The headline numbers of the dashboard.
     *
     * @param  array{branch_id?: int|null}  $filters
     * @return array<string, mixed>
     */
    public function overview(array $filters): array
    {
        $portfolio = $this->activeAccounts($filters)->first([
            DB::raw('COUNT(*) as loans'),
            DB::raw('SUM(loans.outstanding_principal) as outstanding_principal'),
            DB::raw('SUM(loans.arrears_amount) as arrears'),
            DB::raw('SUM(CASE WHEN loans.days_past_due > 30 THEN loans.outstanding_principal ELSE 0 END) as par30_principal'),
        ]);

        $month = ['from' => BusinessDate::today()->startOfMonth()->toDateString(), 'to' => BusinessDate::today()->toDateString()] + $filters;
        $pipeline = [LoanStatus::Submitted, LoanStatus::InReview, LoanStatus::Returned, LoanStatus::Verified, LoanStatus::Approved, LoanStatus::Accepted];

        $applications = Loan::topLevel()
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->whereIn('status', array_map(fn ($s) => $s->value, $pipeline))
            ->groupBy('status')
            ->get(['status', DB::raw('COUNT(*) as total')])
            ->mapWithKeys(fn ($row) => [$row->status->value => (int) $row->total]);

        return [
            'active_loans' => (int) $portfolio->loans,
            'outstanding_principal' => $this->money($portfolio->outstanding_principal),
            'arrears' => $this->money($portfolio->arrears),
            'par30_percent' => $this->percent($portfolio->par30_principal, $portfolio->outstanding_principal),
            'applications_in_pipeline' => collect($pipeline)->mapWithKeys(fn ($s) => [$s->value => (int) ($applications[$s->value] ?? 0)])->all(),
            'disbursed_this_month' => (string) Money::sum(array_column($this->disbursements($month), 'amount')),
            'collected_this_month' => $this->collections($month)['net_total'],
            'open_recovery_cases' => RecoveryCase::open()->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))->count(),
            'pending_approvals' => ApprovalRequest::where('status', ApprovalStatus::Pending->value)->count(),
        ];
    }

    /**
     * @param  array{branch_id?: int|null}  $filters
     */
    protected function accounts(array $filters): Builder
    {
        return Loan::query()->accounts()->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('loans.branch_id', $id));
    }

    /**
     * @param  array{branch_id?: int|null}  $filters
     */
    protected function activeAccounts(array $filters): Builder
    {
        return $this->accounts($filters)->where('loans.status', LoanStatus::Active->value);
    }

    /**
     * Ledger rows of loans the user can see, in the date range.
     *
     * @param  array{from: string, to: string, branch_id?: int|null}  $filters
     */
    protected function transactions(array $filters): Builder
    {
        return LoanTransaction::query()
            ->whereHas('loan', fn ($q) => $q->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id)))
            // from ≤ date < the day after "to": right for a DATE column and for a stored datetime alike.
            ->where('loan_transactions.value_date', '>=', $filters['from'])
            ->where('loan_transactions.value_date', '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateString());
    }

    /**
     * key => SUM(column) of a grouped query.
     *
     * @return Collection<int|string, string>
     */
    protected function sumBy(Builder $query, string $key, string $column): Collection
    {
        return $query->get([DB::raw("{$key} as group_key"), DB::raw("SUM({$column}) as total")])
            ->mapWithKeys(fn ($row) => [$row->group_key => (string) $row->total]);
    }

    protected function money(mixed $value): string
    {
        return (string) Money::of((string) ($value ?? 0));
    }

    protected function percent(mixed $part, mixed $whole): string
    {
        $whole = Money::of((string) ($whole ?? 0));

        return $whole->isZero() ? '0.00' : (string) Money::divide(Money::of((string) ($part ?? 0))->multipliedBy(100), $whole)->toScale(2, RoundingMode::HALF_UP);
    }

    protected function start(string $date): string
    {
        return CarbonImmutable::parse($date)->startOfDay()->toDateTimeString();
    }

    protected function end(string $date): string
    {
        return CarbonImmutable::parse($date)->endOfDay()->toDateTimeString();
    }
}

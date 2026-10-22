<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\LoanStatus;
use App\Enums\TransactionType;
use App\Exceptions\BusinessRuleException;
use App\Finance\Support\Money;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanTransaction;
use App\Models\RecoveryCase;
use App\Models\Setting;
use App\Support\BranchAccess;
use App\Support\BusinessDate;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The management dashboard: what a lender needs to see at a glance, answered from the same figures
 * as the reports (every number is a report figure, so the dashboard and the reports cannot disagree).
 *
 * Sections (the standard microfinance / lending set: growth, portfolio quality, collections, pipeline):
 *  - kpis        disbursed, collected, applications, approval rate, new customers (each against the
 *                period before), outstanding portfolio, PAR 30 / PAR 90, arrears, collection rate
 *  - targets     disbursement, collection and approval rate against targets kept in settings
 *  - pipeline    applications waiting at each step, with the money they stand for
 *  - portfolio   outstanding by product with its share and PAR
 *  - arrears_aging, recovery, upcoming_dues, trend, branches (head office), recent activity
 *
 * Branch users see their branch; head office sees every branch or picks one with ?branch_id=.
 */
class DashboardService
{
    /**
     * Longest period the dashboard covers (days), as for the reports.
     */
    private const MAX_PERIOD_DAYS = 366;

    public function __construct(
        protected ReportService $reports,
    ) {}

    /**
     * @param  array{branch_id?: int|null, from?: string|null, to?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function overview(array $filters): array
    {
        $branch = $filters['branch_id'] ?? null;
        $today = BusinessDate::todayImmutable();
        $from = CarbonImmutable::parse($filters['from'] ?? $today->startOfMonth()->toDateString());
        $to = CarbonImmutable::parse($filters['to'] ?? $today->toDateString());

        if ($from->diffInDays($to) > self::MAX_PERIOD_DAYS) {
            throw new BusinessRuleException('REPORT_PERIOD_TOO_LONG', 'The dashboard covers at most one year.', 422);
        }

        // The period just before, as long as this one, for "up / down" arrows.
        $days = (int) $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);

        $period = $this->period($from, $to, $branch);
        $previous = $this->period($previousFrom, $previousTo, $branch);

        // The headline numbers the first version of the dashboard returned stay where they were.
        $legacy = $this->reports->overview(['branch_id' => $branch]);

        $quality = $this->quality($branch);
        $applications = $this->applications($from, $to, $branch);
        $previousApplications = $this->applications($previousFrom, $previousTo, $branch);

        $kpis = [
            'disbursed' => $this->compare($period['disbursed'], $previous['disbursed']) + ['loans' => $period['disbursed_loans']],
            'collected' => $this->compare($period['collected'], $previous['collected']),
            'applications_received' => $this->compare((string) $applications['received'], (string) $previousApplications['received'], 0),
            'new_customers' => $this->compare((string) $this->newCustomers($from, $to), (string) $this->newCustomers($previousFrom, $previousTo), 0),
            'approval_rate_percent' => $applications['approval_rate'],
            'approved' => $applications['approved'],
            'rejected' => $applications['rejected'],
            'outstanding_principal' => $legacy['outstanding_principal'],
            'active_loans' => $legacy['active_loans'],
            'arrears' => $legacy['arrears'],
            'arrears_loans' => $quality['arrears_loans'],
            'par30_percent' => $quality['par30'],
            'par90_percent' => $quality['par90'],
            'collection_rate_percent' => $this->collectionRate($from, $to, $branch),
        ];

        return $legacy + [
            // Legacy keys above describe the month to date; this period is what the new sections describe.
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'previous_from' => $previousFrom->toDateString(), 'previous_to' => $previousTo->toDateString(), 'days' => $days],
            'kpis' => $kpis,
            'targets' => $this->targets($kpis, $days),
            'pipeline' => $this->pipeline($branch),
            'portfolio_by_product' => $this->portfolioByProduct($branch),
            'arrears_aging' => $this->reports->arrearsAging(['branch_id' => $branch]),
            'recovery' => $this->recovery($branch),
            'upcoming_dues' => $this->upcomingDues($today, $branch),
            'trend' => $this->reports->monthlyTrend(6, ['branch_id' => $branch]),
            'branches' => $branch === null && BranchAccess::restrictedBranchId() === null ? $this->reports->branchPerformance(['from' => $from->toDateString(), 'to' => $to->toDateString()]) : [],
            'recent_transactions' => $this->recentTransactions($branch),
            'recent_applications' => $this->recentApplications($branch),
        ];
    }

    /**
     * Money paid out and collected in a period, from the same reports the report screens use.
     *
     * @return array{disbursed: string, disbursed_loans: int, collected: string}
     */
    protected function period(CarbonImmutable $from, CarbonImmutable $to, ?int $branch): array
    {
        $filters = ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'branch_id' => $branch];
        $disbursements = $this->reports->disbursements($filters);

        return [
            'disbursed' => (string) Money::sum(array_column($disbursements, 'amount')),
            'disbursed_loans' => (int) array_sum(array_column($disbursements, 'loans')),
            'collected' => $this->reports->collections($filters)['net_total'],
        ];
    }

    /**
     * @return array{value: string, previous: string, change_percent: string|null}
     */
    protected function compare(string $value, string $previous, int $scale = 2): array
    {
        $change = Money::of($previous)->isZero()
            ? null
            : (string) Money::divide(Money::of($value)->minus($previous)->multipliedBy(100), Money::of($previous)->abs())->toScale(2, RoundingMode::HALF_UP);

        return ['value' => $scale === 0 ? (string) (int) $value : $value, 'previous' => $scale === 0 ? (string) (int) $previous : $previous, 'change_percent' => $change];
    }

    /**
     * Applications that came in, and how the decisions in the period went.
     *
     * @return array{received: int, approved: int, rejected: int, approval_rate: string|null}
     */
    protected function applications(CarbonImmutable $from, CarbonImmutable $to, ?int $branch): array
    {
        $loans = fn () => Loan::query()->topLevel()->when($branch, fn ($q, $id) => $q->where('branch_id', $id));
        $start = $from->startOfDay()->toDateTimeString();
        $end = $to->endOfDay()->toDateTimeString();

        $approved = $loans()->whereBetween('approved_at', [$start, $end])->count();
        $rejected = $loans()->where('status', LoanStatus::Rejected->value)->whereBetween('closed_at', [$start, $end])->count();

        return [
            'received' => $loans()->whereBetween('created_at', [$start, $end])->count(),
            'approved' => $approved,
            'rejected' => $rejected,
            'approval_rate' => $approved + $rejected === 0 ? null : (string) Money::divide($approved * 100, $approved + $rejected)->toScale(2, RoundingMode::HALF_UP),
        ];
    }

    protected function newCustomers(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Customer::query()->whereBetween('created_at', [$from->startOfDay()->toDateTimeString(), $to->endOfDay()->toDateTimeString()])->count();
    }

    /**
     * Portfolio quality of the active loans now: PAR 30 / 90 and how many loans are behind.
     *
     * @return array{par30: string, par90: string, arrears_loans: int}
     */
    protected function quality(?int $branch): array
    {
        $row = $this->active($branch)->first([
            DB::raw('SUM(loans.outstanding_principal) as outstanding'),
            DB::raw('SUM(CASE WHEN loans.days_past_due > 30 THEN loans.outstanding_principal ELSE 0 END) as par30'),
            DB::raw('SUM(CASE WHEN loans.days_past_due > 90 THEN loans.outstanding_principal ELSE 0 END) as par90'),
            DB::raw('SUM(CASE WHEN loans.days_past_due > 0 THEN 1 ELSE 0 END) as behind'),
        ]);

        return [
            'par30' => $this->percent($row->par30, $row->outstanding),
            'par90' => $this->percent($row->par90, $row->outstanding),
            'arrears_loans' => (int) $row->behind,
        ];
    }

    /**
     * Of what fell due in the period (principal, profit and charges, not penalties), how much has been paid.
     */
    protected function collectionRate(CarbonImmutable $from, CarbonImmutable $to, ?int $branch): ?string
    {
        $row = LoanInstallment::query()
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('loan_id', Loan::query()->accounts()->when($branch, fn ($q, $id) => $q->where('loans.branch_id', $id))->select('loans.id'))
            ->first([
                DB::raw('SUM(principal_due + profit_due + charges_due) as due'),
                DB::raw('SUM(principal_paid + profit_paid + charges_paid) as paid'),
            ]);

        return Money::of((string) ($row->due ?? 0))->isZero() ? null : $this->percent(min((float) $row->paid, (float) $row->due), $row->due);
    }

    /**
     * Targets live in settings (0 = no target). Money targets are per month and scale with the period.
     *
     * @param  array<string, mixed>  $kpis
     * @return array<string, array{actual: string|null, target: string|null, percentage: string|null}>
     */
    protected function targets(array $kpis, int $days): array
    {
        $months = max(1, (int) round($days / 30.4375));
        $row = function (?string $actual, string $key, int $scale) {
            $target = (string) Setting::value($key, '0');
            $target = Money::of($target)->multipliedBy($scale);

            return [
                'actual' => $actual,
                'target' => $target->isZero() ? null : (string) $target->toScale(2, RoundingMode::HALF_UP),
                'percentage' => $target->isZero() || $actual === null ? null : (string) Money::divide(Money::of($actual)->multipliedBy(100), $target)->toScale(2, RoundingMode::HALF_UP),
            ];
        };

        return [
            'disbursement' => $row($kpis['disbursed']['value'], 'dashboard.target.disbursement', $months),
            'recovery' => $row($kpis['collected']['value'], 'dashboard.target.collection', $months),
            'approval_rate' => $row($kpis['approval_rate_percent'], 'dashboard.target.approval_rate', 1),
        ];
    }

    /**
     * Applications waiting at each step and the money they stand for.
     *
     * @return list<array{status: string, applications: int, amount: string}>
     */
    protected function pipeline(?int $branch): array
    {
        $steps = [LoanStatus::Submitted, LoanStatus::InReview, LoanStatus::Returned, LoanStatus::Verified, LoanStatus::Approved, LoanStatus::Accepted];

        $rows = Loan::query()->topLevel()
            ->when($branch, fn ($q, $id) => $q->where('branch_id', $id))
            ->whereIn('status', array_map(fn (LoanStatus $s) => $s->value, $steps))
            ->groupBy('status')
            ->get(['status', DB::raw('COUNT(*) as total'), DB::raw('SUM(COALESCE(approved_amount, requested_amount)) as amount')])
            ->keyBy(fn ($r) => $r->status->value);

        return array_map(fn (LoanStatus $s) => [
            'status' => $s->value,
            'applications' => (int) ($rows[$s->value]->total ?? 0),
            'amount' => $this->money($rows[$s->value]->amount ?? 0),
        ], $steps);
    }

    /**
     * Outstanding principal by product, with its share of the whole and its PAR 30.
     *
     * @return list<array<string, mixed>>
     */
    protected function portfolioByProduct(?int $branch): array
    {
        $rows = $this->reports->portfolio(['branch_id' => $branch]);
        $total = Money::sum(array_column($rows, 'outstanding_principal'));

        return array_map(fn (array $r) => [
            'product' => $r['product'],
            'loans' => $r['loans'],
            'outstanding_principal' => $r['outstanding_principal'],
            'share_percent' => $total->isZero() ? '0.00' : (string) Money::divide(Money::of($r['outstanding_principal'])->multipliedBy(100), $total)->toScale(2, RoundingMode::HALF_UP),
            'par30_percent' => $r['par30_percent'],
        ], $rows);
    }

    /**
     * @return array{open_cases: int, opened_arrears: string, by_stage: list<array<string, mixed>>}
     */
    protected function recovery(?int $branch): array
    {
        $open = RecoveryCase::open()->when($branch, fn ($q, $id) => $q->where('branch_id', $id))
            ->groupBy('stage')
            ->get(['stage', DB::raw('COUNT(*) as cases'), DB::raw('SUM(opened_arrears) as arrears')]);

        return [
            'open_cases' => (int) $open->sum('cases'),
            'opened_arrears' => $this->money($open->sum('arrears')),
            'by_stage' => $open->map(fn ($r) => ['stage' => $r->stage->value, 'cases' => (int) $r->cases, 'opened_arrears' => $this->money($r->arrears)])->values()->all(),
        ];
    }

    /**
     * Installments falling due in the next seven days, so collectors know what is coming.
     *
     * @return array{from: string, to: string, installments: int, amount: string}
     */
    protected function upcomingDues(CarbonImmutable $today, ?int $branch): array
    {
        $to = $today->addDays(7);

        $row = LoanInstallment::query()
            ->whereBetween('due_date', [$today->toDateString(), $to->toDateString()])
            ->where('balance', '>', 0)
            ->whereIn('loan_id', Loan::query()->accounts()->where('loans.status', LoanStatus::Active->value)->when($branch, fn ($q, $id) => $q->where('loans.branch_id', $id))->select('loans.id'))
            ->first([DB::raw('COUNT(*) as total'), DB::raw('SUM(balance) as amount')]);

        return ['from' => $today->toDateString(), 'to' => $to->toDateString(), 'installments' => (int) $row->total, 'amount' => $this->money($row->amount)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function recentTransactions(?int $branch): array
    {
        return LoanTransaction::query()
            ->with('loan.customer')
            ->whereHas('loan', fn ($q) => $q->when($branch, fn ($q, $id) => $q->where('branch_id', $id)))
            ->whereIn('type', [TransactionType::Repayment->value, TransactionType::EarlySettlement->value, TransactionType::Reversal->value])
            ->latest('id')->limit(5)->get()
            ->map(fn (LoanTransaction $t) => [
                'public_id' => $t->public_id,
                'txn_no' => $t->txn_no,
                'loan_no' => $t->loan->loan_no,
                'borrower' => $t->loan->customer?->full_name,
                'type' => $t->type->value,
                'amount' => $this->money($t->amount),
                'value_date' => $t->value_date->toDateString(),
                'channel' => $t->channel->value,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function recentApplications(?int $branch): array
    {
        return Loan::query()->topLevel()->with(['customer', 'borrowerGroup', 'product'])
            ->when($branch, fn ($q, $id) => $q->where('branch_id', $id))
            ->latest('id')->limit(5)->get()
            ->map(fn (Loan $l) => [
                'public_id' => $l->public_id,
                'application_no' => $l->application_no,
                'product' => $l->product->name,
                'borrower' => $l->customer?->full_name ?? $l->borrowerGroup?->name,
                'amount' => $this->money($l->requested_amount),
                'status' => $l->status->value,
                'applied_at' => $l->created_at?->toApiString(),
            ])->all();
    }

    protected function active(?int $branch)
    {
        return Loan::query()->accounts()->where('loans.status', LoanStatus::Active->value)->when($branch, fn ($q, $id) => $q->where('loans.branch_id', $id));
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
}

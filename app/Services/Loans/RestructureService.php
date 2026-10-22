<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\AllocationComponent;
use App\Enums\ApprovalAction;
use App\Enums\FinanceBasis;
use App\Enums\InstallmentStatus;
use App\Enums\LoanActivityType;
use App\Enums\LoanStatus;
use App\Exceptions\BusinessRuleException;
use App\Finance\Schedule\DueDateGenerator;
use App\Finance\Schedule\LoanTerms;
use App\Finance\Support\Money;
use App\Models\ApprovalRequest;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\LoanPostingService;
use App\Services\Approvals\ApprovalRequestService;
use App\Support\BusinessDate;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * A new repayment schedule for a borrower in difficulty, through maker-checker.
 *
 * The unpaid lines of the schedule in force are marked rescheduled (never deleted) and a new
 * schedule version is written from today:
 *
 * Conventional: unpaid principal plus interest already due becomes the new principal, priced
 *               at the approved rate over the new tenure.
 * Islamic:      the unpaid sale price is only spread over more installments. No new profit is
 *               added, because increasing a debt for more time is riba.
 *
 * Unpaid charges and late charges are not capitalised; they move to the first new installment.
 */
class RestructureService
{
    public function __construct(
        protected LoanWorkflowService $workflow,
        protected LoanBalanceService $balances,
        protected LoanPricingService $pricing,
        protected ApprovalRequestService $approvals,
        protected DueDateGenerator $dueDates,
        protected LoanPostingService $posting,
    ) {}

    /**
     * @param  array{tenure: int, rate?: string|null}  $data
     */
    public function request(Loan $loan, array $data, string $reason, User $by): ApprovalRequest
    {
        $this->assertRestructurable($loan, $data);
        $this->approvals->assertNonePending($loan, ApprovalAction::Restructure);

        return $this->approvals->create($loan, ApprovalAction::Restructure, [
            'tenure' => (int) $data['tenure'],
            'rate' => $loan->finance_basis === FinanceBasis::Islamic ? null : (string) ($data['rate'] ?? $loan->rate),
        ], $reason, $by);
    }

    /**
     * Apply an approved restructure (called by RestructureHandler inside the decision transaction).
     *
     * @param  array{tenure: int, rate: string|null}  $payload
     */
    public function apply(Loan $loan, array $payload, User $approver, string $reason): Loan
    {
        $loan = $this->workflow->lock($loan);
        $this->assertRestructurable($loan, $payload);

        $today = BusinessDate::todayImmutable();
        ['unpaid' => $unpaid, 'lines' => $lines, 'charges' => $charges, 'penalty' => $penalty, 'dueProfit' => $dueProfit, 'rate' => $rate, 'tenure' => $tenure, 'islamic' => $islamic] = $this->plan($loan, $payload, $today);

        $version = (int) $loan->installments()->max('schedule_version') + 1;
        $graceDays = (int) ($this->pricing->termsOf($loan)['grace_period_days'] ?? Setting::value('repayment.grace_period_days', 7));

        // The old unpaid lines stay as history; what they owed moves to the new schedule.
        LoanInstallment::whereIn('id', $unpaid->pluck('id'))->update(['status' => InstallmentStatus::Rescheduled->value]);

        foreach ($lines as $n => [$dueDate, $linePrincipal, $lineProfit]) {
            LoanInstallment::create([
                'loan_id' => $loan->id,
                'schedule_version' => $version,
                'installment_no' => $n + 1,
                'due_date' => $dueDate->toDateString(),
                'principal_due' => (string) $linePrincipal,
                'profit_due' => (string) $lineProfit,
                'charges_due' => (string) ($n === 0 ? $charges : Money::zero()),
                'penalty_due' => (string) ($n === 0 ? $penalty : Money::zero()),
                'status' => InstallmentStatus::Upcoming,
                'grace_ends_on' => $dueDate->addDays($graceDays)->toDateString(),
            ]);
        }

        $this->posting->postRestructure($loan, $unpaid, $islamic ? Money::zero() : $dueProfit, $version);

        $first = $lines[0];
        $loan->forceFill([
            'tenure' => $tenure,
            'rate' => $rate,
            'installment_amount' => (string) $first[1]->plus($first[2]),
            'maturity_date' => end($lines)[0]->toDateString(),
            'schedule_starts_on' => $today->toDateString(),
            'updated_by' => $approver->id,
        ])->save();

        $this->workflow->record($loan, LoanActivityType::Update, $approver, "Restructured to schedule version {$version}: {$reason}", [
            'schedule_version' => $version,
            'tenure' => $tenure,
            'rate' => $rate,
        ]);

        return $this->balances->refresh($loan);
    }

    /**
     * What a restructure would do, without doing it: the new schedule, instalment and maturity date.
     * Nothing is saved and no approval is created; the officer reads it before sending the request.
     *
     * @param  array{tenure: int, rate?: string|null}  $data
     * @return array<string, mixed>
     */
    public function preview(Loan $loan, array $data): array
    {
        $this->assertRestructurable($loan, $data);

        $today = BusinessDate::todayImmutable();
        $plan = $this->plan($loan, ['tenure' => (int) $data['tenure'], 'rate' => $loan->finance_basis === FinanceBasis::Islamic ? null : (string) ($data['rate'] ?? $loan->rate)], $today);

        $schedule = [];

        foreach ($plan['lines'] as $n => [$dueDate, $principal, $profit]) {
            $schedule[] = [
                'installment_no' => $n + 1,
                'due_date' => $dueDate->toDateString(),
                'principal_due' => (string) $principal,
                'profit_due' => (string) $profit,
                'charges_due' => (string) ($n === 0 ? $plan['charges'] : Money::zero()),
                'penalty_due' => (string) ($n === 0 ? $plan['penalty'] : Money::zero()),
                'total_due' => (string) $principal->plus($profit)->plus($n === 0 ? $plan['charges']->plus($plan['penalty']) : Money::zero()),
            ];
        }

        $first = $plan['lines'][0];

        return [
            'from' => ['tenure' => $loan->tenure, 'rate' => (string) $loan->rate, 'installment_amount' => (string) $loan->installment_amount, 'maturity_date' => $loan->maturity_date?->toDateString()],
            'to' => [
                'tenure' => $plan['tenure'],
                'rate' => $plan['rate'],
                'installment_amount' => (string) $first[1]->plus($first[2]),
                'maturity_date' => end($plan['lines'])[0]->toDateString(),
                'starts_on' => $today->toDateString(),
                'schedule_version' => (int) $loan->installments()->max('schedule_version') + 1,
            ],
            'rescheduled' => [
                'principal' => (string) $plan['principal'],
                'charges' => (string) $plan['charges'],
                'penalty' => (string) $plan['penalty'],
                'profit_due_to_date' => (string) $plan['dueProfit'],
            ],
            'schedule' => $schedule,
        ];
    }

    /**
     * The unpaid lines and the new lines of a restructure (shared by preview and apply).
     *
     * @param  array{tenure: int, rate?: string|null}  $payload
     * @return array<string, mixed>
     */
    protected function plan(Loan $loan, array $payload, CarbonImmutable $today): array
    {
        $unpaid = $this->balances->unpaidInstallments($loan);
        $sum = fn (AllocationComponent $c, ?callable $filter = null) => Money::sum(($filter ? $unpaid->filter($filter) : $unpaid)->map(fn (LoanInstallment $i) => $i->unpaid($c)));

        $principal = $sum(AllocationComponent::Principal);
        $charges = $sum(AllocationComponent::Charge);
        $penalty = $sum(AllocationComponent::Penalty);
        $islamic = $loan->finance_basis === FinanceBasis::Islamic;
        $tenure = (int) $payload['tenure'];
        $dueProfit = Money::zero();

        if ($islamic) {
            $lines = $this->spreadSalePrice($loan, $principal, $sum(AllocationComponent::Profit), $tenure, $today);
            $rate = (string) $loan->rate;
        } else {
            $dueProfit = $sum(AllocationComponent::Profit, fn (LoanInstallment $i) => $i->due_date->lessThanOrEqualTo($today));
            $terms = new LoanTerms($principal->plus($dueProfit), $payload['rate'], $tenure, $loan->repayment_frequency, $today);
            $lines = array_map(fn ($l) => [$l->dueDate, $l->principal, $l->profit], $loan->pricing_method->calculator()->buildSchedule($terms)->lines);
            $rate = (string) $payload['rate'];
        }

        return compact('unpaid', 'lines', 'principal', 'charges', 'penalty', 'dueProfit', 'rate', 'tenure', 'islamic');
    }

    /**
     * Islamic: split the remaining principal and profit into equal parts; the last part takes the
     * rounding so the total owed does not change by a cent.
     *
     * @return list<array{0: CarbonImmutable, 1: BigDecimal, 2: BigDecimal}>
     */
    protected function spreadSalePrice(Loan $loan, BigDecimal $principal, BigDecimal $profit, int $tenure, CarbonImmutable $today): array
    {
        $dates = $this->dueDates->generate(new LoanTerms($principal, '0', $tenure, $loan->repayment_frequency, $today));
        $split = function (BigDecimal $total) use ($tenure) {
            $part = Money::round(Money::divide($total, $tenure));
            $parts = array_fill(0, $tenure, $part);
            $parts[$tenure - 1] = $total->minus($part->multipliedBy($tenure - 1));

            return $parts;
        };

        $principalParts = $split($principal);
        $profitParts = $split($profit);

        return array_map(fn ($i) => [$dates[$i], $principalParts[$i], $profitParts[$i]], array_keys($dates));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertRestructurable(Loan $loan, array $data): void
    {
        if ($loan->isGroupFacility()) {
            throw new BusinessRuleException('PAY_MEMBER_LOAN', 'Each member loan is restructured on its own.', 422);
        }

        if ($loan->status !== LoanStatus::Active) {
            throw new BusinessRuleException('LOAN_NOT_ACTIVE', 'Only an active loan can be restructured.', 409);
        }

        if ($loan->pricing_method->isBullet()) {
            throw new BusinessRuleException('RESTRUCTURE_NOT_SUPPORTED', 'A one-payment (gold) loan is renewed, not restructured.', 422);
        }

        if (! $this->balances->unpaidInstallments($loan)->contains(fn (LoanInstallment $i) => $i->unpaid(AllocationComponent::Principal)->isPositive())) {
            throw new BusinessRuleException('NOTHING_TO_RESTRUCTURE', 'No principal is left to reschedule.', 422);
        }

        $terms = $this->pricing->termsOf($loan);

        if ($loan->finance_basis !== FinanceBasis::Islamic && isset($data['rate'])) {
            $rate = BigDecimal::of($data['rate']);

            if ($rate->isLessThan($terms['min_rate']) || $rate->isGreaterThan($terms['max_rate'])) {
                throw new BusinessRuleException('RATE_OUT_OF_PRODUCT_LIMITS', "The rate must be between {$terms['min_rate']} and {$terms['max_rate']}% p.a.", 422);
            }
        }

        if ((int) $data['tenure'] > $terms['max_tenure']) {
            throw new BusinessRuleException('TENURE_OUT_OF_PRODUCT_LIMITS', "The new tenure can be at most {$terms['max_tenure']} installments.", 422);
        }
    }
}

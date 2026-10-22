<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\ChargeBehaviour;
use App\Enums\ChargeCalculation;
use App\Enums\ChargeCollection;
use App\Finance\Charges\ChargeCalculator;
use App\Finance\Schedule\LoanTerms;
use App\Finance\Support\Money;
use App\Models\Loan;
use App\Models\Product;
use App\Models\ProductCharge;
use App\Models\ProductCollateralRule;
use App\Support\BusinessDate;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Prices a loan from its own terms snapshot, never from the live product.
 *
 * The snapshot is taken when the application is submitted, so a product edited later (new rate,
 * new fees) never changes an application already in the pipeline or a running loan.
 */
class LoanPricingService
{
    public function __construct(
        protected ChargeCalculator $chargeCalculator,
    ) {}

    /**
     * Everything about the product that the loan depends on, frozen at submission.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Product $product): array
    {
        $product->loadMissing(['category', 'charges', 'collateralRules']);

        return [
            'product' => ['id' => $product->id, 'slug' => $product->slug, 'name' => $product->name, 'category' => $product->category->slug],
            'finance_basis' => $product->financeBasis()->value,
            'pricing_method' => $product->pricing_method->value,
            'repayment_frequency' => $product->repayment_frequency->value,
            'default_rate' => (string) $product->default_rate,
            'min_rate' => (string) $product->min_rate,
            'max_rate' => (string) $product->max_rate,
            'min_amount' => (string) $product->min_amount,
            'max_amount' => (string) $product->max_amount,
            'min_tenure' => $product->min_tenure,
            'max_tenure' => $product->max_tenure,
            'min_group_members' => $product->min_group_members,
            'max_group_members' => $product->max_group_members,
            'min_guarantors' => $product->min_guarantors,
            'max_guarantors' => $product->max_guarantors,
            'requires_collateral' => $product->requires_collateral,
            'grace_period_days' => $product->grace_period_days,
            'max_dsr_percent' => $product->max_dsr_percent !== null ? (string) $product->max_dsr_percent : null,
            'residual_value_percent' => $product->residual_value_percent !== null ? (string) $product->residual_value_percent : null,
            'early_settlement_allowed' => $product->early_settlement_allowed,
            'early_settlement_rebate_percent' => $product->early_settlement_rebate_percent !== null ? (string) $product->early_settlement_rebate_percent : null,
            // DECISION: the charge's name, display group and behaviour are frozen with the terms, so a
            // fee renamed or regrouped later never changes what an existing loan shows or charges.
            'charges' => $product->charges->where('is_active', true)->map(fn (ProductCharge $charge) => [
                'charge_type' => $charge->type()->code,
                'name' => $charge->type()->name,
                'display_group' => $charge->type()->display_group,
                'behaviour' => $charge->type()->behaviour->value,
                'calculation' => $charge->calculation->value,
                'value' => (string) $charge->value,
                'min_amount' => $charge->min_amount !== null ? (string) $charge->min_amount : null,
                'max_amount' => $charge->max_amount !== null ? (string) $charge->max_amount : null,
                'collection' => $charge->collection->value,
                'recurrence' => $charge->recurrence->value,
                'is_charity' => $charge->is_charity,
            ])->values()->all(),
            'collateral_rules' => $product->collateralRules->map(fn (ProductCollateralRule $rule) => [
                'collateral_type' => $rule->collateral_type,
                'max_ltv_percent' => (string) $rule->max_ltv_percent,
                'is_mandatory' => $rule->is_mandatory,
            ])->values()->all(),
            'captured_at' => now()->toApiString(),
        ];
    }

    /**
     * The terms a loan runs on: its snapshot once submitted, the live product while still a draft.
     *
     * @return array<string, mixed>
     */
    public function termsOf(Loan $loan): array
    {
        return $loan->terms_snapshot ?? $this->snapshot($loan->product);
    }

    public function price(Loan $loan, BigDecimal|string $amount, BigDecimal|string $rate, ?CarbonImmutable $startDate = null, ?CarbonImmutable $firstDueDate = null): LoanPricing
    {
        $terms = $this->termsOf($loan);
        $amount = Money::of($amount);
        $residual = $terms['residual_value_percent'] !== null ? Money::percentOf($amount, $terms['residual_value_percent']) : null;

        $loanTerms = new LoanTerms(
            principal: $amount,
            annualRate: $rate,
            tenure: $loan->tenure,
            frequency: $loan->repayment_frequency,
            startDate: $startDate ?? BusinessDate::todayImmutable(),
            firstDueDate: $firstDueDate,
            residualValue: $residual,
        );

        $schedule = $loan->pricing_method->calculator()->buildSchedule($loanTerms);

        [$charges, $deducted, $firstInstallment] = $this->upfrontCharges($terms['charges'], $amount);

        return new LoanPricing($amount, $schedule, $charges, $deducted, $firstInstallment);
    }

    /**
     * Charges taken at the start (event charges such as late payment are raised later).
     *
     * @param  list<array<string, mixed>>  $charges
     * @return array{0: list<array{charge_type: string, name: string, display_group: string, collection: string, amount: string}>, 1: BigDecimal, 2: BigDecimal}
     */
    protected function upfrontCharges(array $charges, BigDecimal $amount): array
    {
        $lines = [];
        $deducted = Money::zero();
        $firstInstallment = Money::zero();

        foreach ($charges as $charge) {
            if (ChargeBehaviour::from($charge['behaviour'])->isEventDriven()) {
                continue;
            }

            $value = $this->chargeCalculator->amount(
                ChargeCalculation::from($charge['calculation']),
                $charge['value'],
                $amount,
                $charge['min_amount'],
                $charge['max_amount'],
            );

            match (ChargeCollection::from($charge['collection'])) {
                ChargeCollection::DeductFromDisbursement => $deducted = $deducted->plus($value),
                ChargeCollection::AddToFirstInstallment => $firstInstallment = $firstInstallment->plus($value),
                ChargeCollection::OnEvent => null,
            };

            $lines[] = [
                'charge_type' => $charge['charge_type'],
                'name' => $charge['name'],
                'display_group' => $charge['display_group'],
                'collection' => $charge['collection'],
                'amount' => (string) $value,
            ];
        }

        return [$lines, $deducted, $firstInstallment];
    }
}

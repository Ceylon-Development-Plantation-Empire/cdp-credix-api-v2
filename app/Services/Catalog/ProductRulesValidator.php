<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ChargeBehaviour;
use App\Enums\ChargeCalculation;
use App\Enums\FinanceBasis;
use App\Enums\PricingMethod;
use App\Enums\RepaymentFrequency;
use App\Exceptions\BusinessRuleException;
use App\Support\Charges\ChargeTypeRegistry;
use App\Support\Collateral\CollateralTypes;
use BackedEnum;
use Brick\Math\BigDecimal;

/**
 * Checks that a product's terms make sense as a whole.
 *
 * FormRequests check each field on its own (a number, within range). This class checks the
 * combinations a request cannot see, against the final state after a partial update:
 * an Islamic product priced with interest, a qard with a rate, a weekly gold loan, a fee that is
 * a % of a benevolent loan … Every problem is collected and returned at once so the user can fix
 * the whole form in one go.
 */
class ProductRulesValidator
{
    public function __construct(
        protected ChargeTypeRegistry $chargeTypes,
    ) {}

    /**
     * @param  array<string, mixed>  $terms  Product attributes (enums or their values).
     * @param  list<array<string, mixed>>  $charges
     * @param  list<array<string, mixed>>  $collateralRules
     *
     * @throws BusinessRuleException
     */
    public function validate(FinanceBasis $basis, array $terms, array $charges, array $collateralRules): void
    {
        $errors = [
            ...$this->termErrors($basis, $terms),
            ...$this->chargeErrors($basis, $terms, $charges),
            ...$this->collateralErrors($collateralRules),
        ];

        if ($errors) {
            throw new BusinessRuleException('PRODUCT_INVALID_TERMS', 'The product terms are not valid.', 422, ['errors' => $errors]);
        }
    }

    /**
     * Extra checks that only apply before a product can be sold.
     *
     * @param  array<string, mixed>  $terms
     * @param  list<array<string, mixed>>  $collateralRules
     */
    public function validateForActivation(array $terms, array $collateralRules): void
    {
        $errors = [];
        $method = PricingMethod::from($this->value($terms['pricing_method']));
        if ($terms['requires_collateral'] && ! $collateralRules) {
            $errors[] = 'A product that requires collateral needs at least one collateral rule.';
        }

        if ($method->isBullet()) {
            // The engine values, renews and charges safekeeping on gold by weight, so a gold loan must
            // be secured by a type valued by gold weight.
            $goldCodes = app(CollateralTypes::class)->goldCodes();
            $gold = collect($collateralRules)->first(fn ($rule) => in_array($this->value($rule['collateral_type']), $goldCodes, true));

            if (! $gold || ! $gold['is_mandatory']) {
                $errors[] = 'A gold product needs a mandatory gold collateral rule.';
            }
        }

        // DECISION: collateral is optional unless the product says otherwise. A product that does not
        // require collateral may still list the types (and so the lending-margin plans) it accepts.

        if ($errors) {
            throw new BusinessRuleException('PRODUCT_NOT_READY', 'The product cannot be activated yet.', 422, ['errors' => $errors]);
        }
    }

    /**
     * @param  array<string, mixed>  $terms
     * @return list<string>
     */
    protected function termErrors(FinanceBasis $basis, array $terms): array
    {
        $errors = [];
        $method = PricingMethod::from($this->value($terms['pricing_method']));
        $frequency = RepaymentFrequency::from($this->value($terms['repayment_frequency']));

        if (! $method->isAllowedFor($basis)) {
            $errors[] = "{$method->label()} cannot be used for a {$basis->label()} product.";
        }

        $minRate = BigDecimal::of($terms['min_rate']);
        $defaultRate = BigDecimal::of($terms['default_rate']);
        $maxRate = BigDecimal::of($terms['max_rate']);

        if ($minRate->isGreaterThan($defaultRate) || $defaultRate->isGreaterThan($maxRate)) {
            $errors[] = 'Rates must follow min rate ≤ default rate ≤ max rate.';
        }

        if ($method->isProfitFree() && ! ($minRate->isZero() && $maxRate->isZero())) {
            $errors[] = "{$method->label()} cannot carry a profit rate; all rates must be 0.";
        }

        if (BigDecimal::of($terms['min_amount'])->isGreaterThan($terms['max_amount'])) {
            $errors[] = 'Minimum amount cannot be more than the maximum amount.';
        }

        if ((int) $terms['min_tenure'] > (int) $terms['max_tenure']) {
            $errors[] = 'Minimum tenure cannot be more than the maximum tenure.';
        }

        // Gold products are repaid in one go; installment products are not. A qard can be either.
        if ($method->isBullet() && $frequency !== RepaymentFrequency::Bullet) {
            $errors[] = "{$method->label()} must use the bullet repayment frequency.";
        }

        if (! $method->isBullet() && $method !== PricingMethod::QardHasan && $frequency === RepaymentFrequency::Bullet) {
            $errors[] = "{$method->label()} cannot use the bullet repayment frequency.";
        }

        if (! empty($terms['residual_value_percent']) && ! $method->supportsResidualValue()) {
            $errors[] = 'Only lease and ijarah products can have a residual value.';
        }

        if (! empty($terms['early_settlement_rebate_percent']) && $basis !== FinanceBasis::Islamic) {
            $errors[] = 'An early settlement rebate (ibra\') only applies to Islamic products.';
        }

        if (! $terms['allows_individual'] && ! $terms['allows_joint'] && ! $terms['allows_group']) {
            $errors[] = 'The product must allow at least one borrower type (individual, joint or group).';
        }

        if ($terms['allows_group']) {
            $min = $terms['min_group_members'] ?? null;
            $max = $terms['max_group_members'] ?? null;

            if ($min === null || $max === null || $min < 2 || $min > $max) {
                $errors[] = 'A group product needs min group members of at least 2 and not more than max group members.';
            }
        }

        if ((int) $terms['min_guarantors'] > (int) $terms['max_guarantors']) {
            $errors[] = 'Minimum guarantors cannot be more than the maximum guarantors.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $terms
     * @param  list<array<string, mixed>>  $charges
     * @return list<string>
     */
    protected function chargeErrors(FinanceBasis $basis, array $terms, array $charges): array
    {
        $errors = [];
        $method = PricingMethod::from($this->value($terms['pricing_method']));
        $seen = [];

        foreach ($charges as $charge) {
            $code = $this->value($charge['charge_type']);
            $type = $this->chargeTypes->find($code);

            if ($type === null) {
                $errors[] = "The charge type {$code} does not exist.";

                continue;
            }

            $behaviour = $type->behaviour;
            $calculation = ChargeCalculation::from($this->value($charge['calculation']));
            $name = str_replace('_', ' ', $type->code);

            if (isset($seen[$type->code])) {
                $errors[] = "The {$name} is set more than once.";
            }
            $seen[$type->code] = true;

            // The type's own lists (data), never wider than what its behaviour supports (code).
            if (! in_array($calculation->value, $type->allowed_calculations, true)
                || ! in_array($calculation, $behaviour->permittedCalculations(), true)) {
                $errors[] = "The {$name} cannot be calculated as {$calculation->value}.";
            }

            if (! in_array($this->value($charge['collection']), array_map(fn ($c) => $c->value, $behaviour->allowedCollections()), true)) {
                $errors[] = "The {$name} cannot be collected as {$this->value($charge['collection'])}.";
            }

            $recurrence = $this->value($charge['recurrence'] ?? 'once');
            if (! in_array($recurrence, $type->allowed_recurrences, true)
                || ! in_array($recurrence, array_map(fn ($r) => $r->value, $behaviour->permittedRecurrences()), true)) {
                $errors[] = "The {$name} cannot recur as {$recurrence}.";
            }

            if ($calculation->isPercentage() && BigDecimal::of($charge['value'])->isGreaterThan(100)) {
                $errors[] = "The {$name} percentage cannot be more than 100.";
            }

            if (isset($charge['min_amount'], $charge['max_amount']) && BigDecimal::of($charge['min_amount'])->isGreaterThan($charge['max_amount'])) {
                $errors[] = "The {$name} minimum cannot be more than its maximum.";
            }

            // Islamic late payment charges are given to charity; a conventional penalty is income.
            if ($behaviour === ChargeBehaviour::LatePayment && (bool) ($charge['is_charity'] ?? false) !== ($basis === FinanceBasis::Islamic)) {
                $errors[] = $basis === FinanceBasis::Islamic
                    ? 'An Islamic late payment charge must go to charity (is_charity = true).'
                    : 'A conventional late payment charge cannot be marked as charity.';
            }

            if ($behaviour !== ChargeBehaviour::LatePayment && ($charge['is_charity'] ?? false)) {
                $errors[] = "Only the late payment charge can go to charity, not the {$name}.";
            }

            // On a benevolent loan any charge tied to the loan amount would be riba in disguise.
            if ($method->isProfitFree() && in_array($calculation, [ChargeCalculation::PercentOfPrincipal, ChargeCalculation::PercentOfOutstanding], true)) {
                $errors[] = "On a {$method->label()} the {$name} must be a fixed actual-cost amount, not a percentage of the loan.";
            }

            if ($behaviour === ChargeBehaviour::Safekeeping && ! $method->isBullet()) {
                $errors[] = 'A safekeeping fee only applies to gold products.';
            }
        }

        return $errors;
    }

    /**
     * @param  list<array<string, mixed>>  $collateralRules
     * @return list<string>
     */
    protected function collateralErrors(array $collateralRules): array
    {
        $errors = [];
        $seen = [];

        foreach ($collateralRules as $rule) {
            $type = $this->value($rule['collateral_type']);

            if (isset($seen[$type])) {
                $errors[] = "Collateral type {$type} is set more than once.";
            }
            $seen[$type] = true;

            $ltv = BigDecimal::of($rule['max_ltv_percent']);

            if (! $ltv->isGreaterThan(0) || $ltv->isGreaterThan(100)) {
                $errors[] = "The LTV for {$type} must be more than 0 and at most 100.";
            }
        }

        return $errors;
    }

    /**
     * Terms arrive as enums (from a model) or as plain strings (from a request).
     */
    protected function value(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}

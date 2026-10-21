<?php

namespace App\Enums;

/**
 * Kinds of security a loan can be backed by. A new type needs code (its own fields to
 * validate), which is why this is an enum and not a table.
 */
enum CollateralType: string
{
    case PropertyMortgage = 'property_mortgage';
    case Vehicle = 'vehicle';
    case Gold = 'gold';
    case FixedDeposit = 'fixed_deposit';
    case CdpInvestment = 'cdp_investment';

    public function label(): string
    {
        return match ($this) {
            self::PropertyMortgage => 'Property mortgage',
            self::Vehicle => 'Vehicle',
            self::Gold => 'Gold',
            self::FixedDeposit => 'Fixed deposit lien',
            self::CdpInvestment => 'CDP investment',
        };
    }

    /**
     * Validation rules for the type-specific details stored in collaterals.details.
     * Only these keys are kept; anything else the client sends is dropped.
     *
     * @return array<string, list<string>>
     */
    public function detailRules(): array
    {
        return match ($this) {
            self::PropertyMortgage => [
                'deed_no' => ['required', 'string', 'max:50'],
                'deed_date' => ['nullable', 'date', 'before_or_equal:today'],
                'notary' => ['nullable', 'string', 'max:150'],
                'plan_no' => ['nullable', 'string', 'max:50'],
                'lot_no' => ['nullable', 'string', 'max:20'],
                'extent' => ['required', 'string', 'max:50'],
                'address' => ['required', 'string', 'max:255'],
            ],
            self::Vehicle => [
                'registration_no' => ['required', 'string', 'max:20'],
                'chassis_no' => ['required', 'string', 'max:40'],
                'engine_no' => ['required', 'string', 'max:40'],
                'make' => ['required', 'string', 'max:50'],
                'model' => ['required', 'string', 'max:50'],
                'year' => ['required', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            ],
            self::Gold => [
                'item' => ['required', 'string', 'max:100'],
                'karat' => ['required', 'integer', 'min:1', 'max:24'],
                'gross_weight_g' => ['required', 'decimal:0,3', 'gt:0', 'max:100000'],
                'net_weight_g' => ['required', 'decimal:0,3', 'gt:0', 'lte:details.gross_weight_g'],
                // Market rate for one gram of 24K gold on the valuation date.
                'rate_per_gram_24k' => ['required', 'decimal:0,2', 'gt:0', 'max:1000000'],
            ],
            self::FixedDeposit, self::CdpInvestment => [
                'account_no' => ['required', 'string', 'max:40'],
                'institution' => ['required', 'string', 'max:150'],
                'maturity_date' => ['nullable', 'date'],
            ],
        };
    }

    /**
     * Documents that prove the security. Checked before the collateral can be verified.
     *
     * @return list<string> document type slugs
     */
    public function requiredDocuments(): array
    {
        return match ($this) {
            self::PropertyMortgage => ['property-deed', 'valuation-report'],
            self::Vehicle => ['vehicle-cr', 'valuation-report'],
            self::Gold => ['gold-valuation-slip'],
            self::FixedDeposit, self::CdpInvestment => [],
        };
    }

    /**
     * Gold is valued from its weight and purity, not typed in by hand.
     */
    public function isValuedByWeight(): bool
    {
        return $this === self::Gold;
    }
}

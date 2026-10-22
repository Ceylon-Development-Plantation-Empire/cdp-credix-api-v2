<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the value of a collateral type is found. Collateral types themselves are data (lookups of
 * type collateral_type); this is the one part of a type that the engine has to understand.
 */
enum CollateralValuation: string
{
    // The valuer's market value is entered (property, vehicle, deposit, investment, any new type).
    case MarketValue = 'market_value';
    // Worked out from net weight, karat and the 24K rate, never typed in (gold).
    case GoldWeight = 'gold_weight';

    public function label(): string
    {
        return match ($this) {
            self::MarketValue => 'Market value entered by the valuer',
            self::GoldWeight => 'Gold: net weight × karat / 24 × 24K rate',
        };
    }

    /**
     * Detail fields the engine reads for this valuation. They are always validated as required,
     * whatever the type's own field list says, because the value cannot be worked out without them.
     *
     * @return array<string, list<string>>
     */
    public function engineFields(): array
    {
        return match ($this) {
            self::MarketValue => [],
            self::GoldWeight => [
                'karat' => ['required', 'integer', 'min:1', 'max:24'],
                'gross_weight_g' => ['required', 'decimal:0,3', 'gt:0', 'max:100000'],
                'net_weight_g' => ['required', 'decimal:0,3', 'gt:0', 'lte:details.gross_weight_g'],
                // Market rate for one gram of 24K gold on the valuation date.
                'rate_per_gram_24k' => ['required', 'decimal:0,2', 'gt:0', 'max:1000000'],
            ],
        };
    }
}

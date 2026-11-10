<?php

declare(strict_types=1);

namespace App\Enums;

use App\Finance\Pricing\Contracts\PricingCalculator;
use App\Finance\Pricing\FlatRate;
use App\Finance\Pricing\GoldPawn;
use App\Finance\Pricing\Ijarah;
use App\Finance\Pricing\Lease;
use App\Finance\Pricing\Murabaha;
use App\Finance\Pricing\QardHasan;
use App\Finance\Pricing\Rahn;
use App\Finance\Pricing\ReducingBalance;

/**
 * How a product prices its finance.
 *
 * Adding a new method (for example Diminishing Musharakah for Islamic home finance)
 * means: add a case here, add a calculator class in app/Finance/Pricing and map it below.
 * Everything else (products, schedules, payments) works with it automatically.
 */
enum PricingMethod: string
{
    // Conventional
    case Flat = 'flat';
    case ReducingBalance = 'reducing_balance';
    case Lease = 'lease';
    case GoldPawn = 'gold_pawn';

    // Islamic (Shariah)
    case Murabaha = 'murabaha';
    case Ijarah = 'ijarah';
    case QardHasan = 'qard_hasan';
    case Rahn = 'rahn';

    public function label(): string
    {
        return match ($this) {
            self::Flat => 'Flat rate',
            self::ReducingBalance => 'Reducing balance',
            self::Lease => 'Finance lease',
            self::GoldPawn => 'Gold pawning',
            self::Murabaha => 'Murabaha (cost-plus sale)',
            self::Ijarah => 'Ijarah (Islamic lease)',
            self::QardHasan => 'Qard Hasan (benevolent loan)',
            self::Rahn => 'Rahn (Islamic gold)',
        };
    }

    /**
     * The finance basis this method is valid under. An Islamic product can never be
     * priced with interest, and conventional products do not use Shariah contracts.
     */
    public function basis(): FinanceBasis
    {
        return match ($this) {
            self::Flat, self::ReducingBalance, self::Lease, self::GoldPawn => FinanceBasis::Conventional,
            self::Murabaha, self::Ijarah, self::QardHasan, self::Rahn => FinanceBasis::Islamic,
        };
    }

    public function isAllowedFor(FinanceBasis $basis): bool
    {
        return $this->basis() === $basis;
    }

    /**
     * Methods that must carry a zero rate. Qard Hasan and Rahn are benevolent loans:
     * the only allowed cost is a fixed actual-cost fee (or a safekeeping fee for gold).
     */
    public function isProfitFree(): bool
    {
        return in_array($this, [self::QardHasan, self::Rahn], true);
    }

    /**
     * Methods that repay everything in one go at maturity.
     */
    public function isBullet(): bool
    {
        return in_array($this, [self::GoldPawn, self::Rahn], true);
    }

    /**
     * Methods that work with a residual (balloon) value at the end of the term.
     */
    public function supportsResidualValue(): bool
    {
        return in_array($this, [self::Lease, self::Ijarah], true);
    }

    public function calculator(): PricingCalculator
    {
        return match ($this) {
            self::Flat => new FlatRate,
            self::ReducingBalance => new ReducingBalance,
            self::Lease => new Lease,
            self::GoldPawn => new GoldPawn,
            self::Murabaha => new Murabaha,
            self::Ijarah => new Ijarah,
            self::QardHasan => new QardHasan,
            self::Rahn => new Rahn,
        };
    }

    /**
     * Methods available to a basis. Used by the product form dropdown.
     *
     * @return list<self>
     */
    public static function forBasis(FinanceBasis $basis): array
    {
        return array_values(array_filter(self::cases(), fn (self $method) => $method->isAllowedFor($basis)));
    }
}

<?php

use App\Enums\AllocationComponent;
use App\Enums\ChargeCalculation;
use App\Enums\ChargeRecurrence;
use App\Enums\FinanceBasis;
use App\Finance\Affordability\DebtServiceRatio;
use App\Finance\Allocation\AllocationLine;
use App\Finance\Allocation\OutstandingInstallment;
use App\Finance\Allocation\PaymentAllocator;
use App\Finance\Collateral\GoldValuation;
use App\Finance\Delinquency\DelinquencyClassifier;
use App\Finance\Penalty\PenaltyCalculator;
use App\Finance\Settlement\EarlySettlementCalculator;
use App\Finance\Support\Money;
use Carbon\CarbonImmutable;

function allocationMap(array $lines): array
{
    return array_map(fn (AllocationLine $line) => [$line->reference, $line->component->value, (string) $line->amount], $lines);
}

describe('payment allocation', function () {
    $installments = fn () => [
        new OutstandingInstallment(2, CarbonImmutable::parse('2026-02-15'), ['principal' => '8000', 'profit' => '2000']),
        new OutstandingInstallment(1, CarbonImmutable::parse('2026-01-15'), ['principal' => '8000', 'profit' => '2000', 'penalty' => '500']),
    ];

    it('settles the oldest installment first, in waterfall order', function () use ($installments) {
        $lines = (new PaymentAllocator)->allocate('11000', $installments(), AllocationComponent::defaultOrder(FinanceBasis::Conventional));

        expect(allocationMap($lines))->toBe([
            [1, 'penalty', '500.00'],
            [1, 'profit', '2000.00'],
            [1, 'principal', '8000.00'],
            [2, 'profit', '500.00'],
        ]);
    });

    it('pays the islamic installment before late charges', function () use ($installments) {
        $lines = (new PaymentAllocator)->allocate('10000', $installments(), AllocationComponent::defaultOrder(FinanceBasis::Islamic));

        expect(allocationMap($lines))->toBe([
            [1, 'profit', '2000.00'],
            [1, 'principal', '8000.00'],
        ]);
    });

    it('keeps any excess as an advance and always sums to the payment', function () use ($installments) {
        $lines = (new PaymentAllocator)->allocate('25000', $installments(), AllocationComponent::defaultOrder(FinanceBasis::Conventional));
        $total = Money::sum(array_map(fn ($line) => $line->amount, $lines));

        expect(end($lines)->component)->toBe(AllocationComponent::Advance)
            ->and((string) end($lines)->amount)->toBe('4500.00')
            ->and((string) $total)->toBe('25000.00');
    });

    it('rejects a zero payment', function () use ($installments) {
        (new PaymentAllocator)->allocate('0', $installments(), AllocationComponent::defaultOrder(FinanceBasis::Conventional));
    })->throws(InvalidArgumentException::class);
});

describe('penalty', function () {
    it('supports a fixed amount and a percentage of the overdue installment', function () {
        $calculator = new PenaltyCalculator;

        expect((string) $calculator->amount(ChargeCalculation::Fixed, '250', '10333.33'))->toBe('250.00')
            ->and((string) $calculator->amount(ChargeCalculation::PercentOfOverdue, '2', '10333.33'))->toBe('206.67')
            ->and((string) $calculator->amount(ChargeCalculation::PercentOfOverdue, '2', '1000', minAmount: '100'))->toBe('100.00')
            ->and((string) $calculator->amount(ChargeCalculation::PercentOfOverdue, '2', '100000', maxAmount: '1000'))->toBe('1000.00')
            ->and((string) $calculator->amount(ChargeCalculation::Fixed, '250', '0'))->toBe('0.00');
    });

    it('charges the day after grace ends, then monthly while unpaid', function () {
        $dates = (new PenaltyCalculator)->chargeDates(
            CarbonImmutable::parse('2026-01-10'), 7, ChargeRecurrence::MonthlyWhileOverdue, null, CarbonImmutable::parse('2026-03-20')
        );

        expect(array_map(fn ($date) => $date->toDateString(), $dates))->toBe(['2026-01-18', '2026-02-18', '2026-03-18']);
    });

    it('never charges inside the grace period', function () {
        $dates = (new PenaltyCalculator)->chargeDates(
            CarbonImmutable::parse('2026-01-10'), 7, ChargeRecurrence::MonthlyWhileOverdue, null, CarbonImmutable::parse('2026-01-17')
        );

        expect($dates)->toBe([]);
    });

    it('does not charge a date twice when the nightly job runs again', function () {
        $calculator = new PenaltyCalculator;
        $due = CarbonImmutable::parse('2026-01-10');

        $monthly = $calculator->chargeDates($due, 7, ChargeRecurrence::MonthlyWhileOverdue, CarbonImmutable::parse('2026-02-18'), CarbonImmutable::parse('2026-03-01'));
        $once = $calculator->chargeDates($due, 7, ChargeRecurrence::Once, CarbonImmutable::parse('2026-01-18'), CarbonImmutable::parse('2026-06-01'));

        expect($monthly)->toBe([])->and($once)->toBe([]);
    });
});

describe('early settlement', function () {
    it('adds accrued interest and the fee for conventional loans', function () {
        $quote = (new EarlySettlementCalculator)->conventional(
            arrears: '0', outstandingPrincipal: '60000', annualRate: '24',
            accrueFrom: CarbonImmutable::parse('2026-06-15'), settlementDate: CarbonImmutable::parse('2026-07-15'), fee: '1200',
        );

        // 60,000 × 0.24 × 30 / 365 = 1,183.56
        expect((string) $quote->accruedProfit)->toBe('1183.56')
            ->and((string) $quote->total())->toBe('62383.56');
    });

    it('gives a rebate on unearned murabaha profit', function () {
        $quote = (new EarlySettlementCalculator)->murabaha(arrears: '0', remainingSalePrice: '62000', unearnedProfit: '8000', rebatePercent: '50');

        expect((string) $quote->rebate)->toBe('4000.00')
            ->and((string) $quote->total())->toBe('58000.00');
    });
});

describe('gold valuation', function () {
    it('values gold on its pure content and applies LTV', function () {
        $valuation = new GoldValuation;
        $value = $valuation->value('10', 22, '30000');

        expect((string) $value)->toBe('275000.00')
            ->and((string) $valuation->eligibleAmount($value, '70'))->toBe('192500.00')
            ->and((string) $valuation->safekeepingFee('10', '25', 6))->toBe('1500.00');
    });

    it('rejects impossible karats', function () {
        (new GoldValuation)->value('10', 25, '30000');
    })->throws(InvalidArgumentException::class);
});

describe('affordability', function () {
    it('computes the debt service ratio with the new installment', function () {
        $dsr = (new DebtServiceRatio)->percent('100000', '20000', '25000');

        expect((string) $dsr)->toBe('45.00')
            ->and((new DebtServiceRatio)->isAffordable($dsr, '60'))->toBeTrue()
            ->and((new DebtServiceRatio)->isAffordable($dsr, '40'))->toBeFalse();
    });
});

describe('delinquency', function () {
    it('counts days from the oldest unpaid due date', function () {
        $classifier = new DelinquencyClassifier;

        expect($classifier->daysPastDue(CarbonImmutable::parse('2026-01-10'), CarbonImmutable::parse('2026-04-10')))->toBe(90)
            ->and($classifier->daysPastDue(null, CarbonImmutable::parse('2026-04-10')))->toBe(0)
            ->and($classifier->daysPastDue(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-04-10')))->toBe(0);
    });

    it('stages loans the SLFRS 9 way', function (int $dpd, int $stage) {
        expect((new DelinquencyClassifier)->riskStage($dpd))->toBe($stage);
    })->with([[0, 1], [30, 1], [31, 2], [90, 2], [91, 3], [180, 3]]);
});

<?php

use App\Enums\FinanceBasis;
use App\Enums\PricingMethod;
use App\Enums\RepaymentFrequency;
use App\Finance\Pricing\Contracts\PricingCalculator;
use App\Finance\Pricing\FlatRate;
use App\Finance\Pricing\GoldPawn;
use App\Finance\Pricing\Ijarah;
use App\Finance\Pricing\Lease;
use App\Finance\Pricing\Murabaha;
use App\Finance\Pricing\QardHasan;
use App\Finance\Pricing\Rahn;
use App\Finance\Pricing\ReducingBalance;
use App\Finance\Schedule\LoanTerms;
use App\Finance\Schedule\Schedule;
use App\Finance\Support\Money;
use Carbon\CarbonImmutable;

function terms(string $principal, string $rate, int $tenure, RepaymentFrequency $frequency = RepaymentFrequency::Monthly, ?string $residual = null): LoanTerms
{
    return new LoanTerms($principal, $rate, $tenure, $frequency, CarbonImmutable::parse('2026-01-15'), null, $residual);
}

// Every schedule, whatever the method, must add back up exactly and end at the residual.
function assertScheduleIsConsistent(Schedule $schedule, string $principal): void
{
    $lineTotal = Money::sum(array_map(fn ($line) => $line->total(), $schedule->lines));

    expect((string) $schedule->totalPrincipal()->plus($schedule->residualValue))->toBe($principal)
        ->and((string) $lineTotal->plus($schedule->residualValue))->toBe((string) $schedule->totalPayable())
        ->and((string) $schedule->lastLine()->closingBalance)->toBe((string) $schedule->residualValue);

    foreach ($schedule->lines as $line) {
        expect($line->principal->isNegative())->toBeFalse()
            ->and($line->profit->isNegative())->toBeFalse();
    }
}

describe('flat rate', function () {
    it('charges interest per annum on the original principal', function () {
        $schedule = (new FlatRate)->buildSchedule(terms('100000', '24', 12));

        expect((string) $schedule->totalProfit())->toBe('24000.00')
            ->and((string) $schedule->totalPayable())->toBe('124000.00')
            ->and((string) $schedule->installmentAmount())->toBe('10333.33')
            ->and((string) $schedule->lines[11]->total())->toBe('10333.37');

        assertScheduleIsConsistent($schedule, '100000.00');
    });

    it('costs twice as much over twice the term', function () {
        $schedule = (new FlatRate)->buildSchedule(terms('100000', '24', 24));

        expect((string) $schedule->totalProfit())->toBe('48000.00');
    });

    it('prices weekly installments on a per annum rate', function () {
        // 52 weeks = 1 year, so the interest is the same as 12 months.
        $schedule = (new FlatRate)->buildSchedule(terms('52000', '20', 52, RepaymentFrequency::Weekly));

        expect((string) $schedule->totalProfit())->toBe('10400.00')
            ->and($schedule->lines[1]->dueDate->toDateString())->toBe('2026-01-29');

        assertScheduleIsConsistent($schedule, '52000.00');
    });
});

describe('reducing balance', function () {
    it('matches the EMI formula', function () {
        $schedule = (new ReducingBalance)->buildSchedule(terms('100000', '24', 12));

        expect((string) $schedule->installmentAmount())->toBe('9455.96')
            ->and((string) $schedule->lines[0]->profit)->toBe('2000.00')
            ->and((string) $schedule->lines[0]->principal)->toBe('7455.96');

        assertScheduleIsConsistent($schedule, '100000.00');
    });

    it('charges interest only on the outstanding balance', function () {
        $schedule = (new ReducingBalance)->buildSchedule(terms('100000', '24', 12));

        expect($schedule->lines[5]->profit->isLessThan($schedule->lines[4]->profit))->toBeTrue()
            ->and($schedule->totalProfit()->isLessThan('24000'))->toBeTrue();
    });

    it('splits the principal evenly at a zero rate', function () {
        $schedule = (new ReducingBalance)->buildSchedule(terms('1000', '0', 3));

        expect((string) $schedule->installmentAmount())->toBe('333.33')
            ->and((string) $schedule->lines[2]->total())->toBe('333.34');

        assertScheduleIsConsistent($schedule, '1000.00');
    });

    it('stays exact over a long term', function () {
        assertScheduleIsConsistent((new ReducingBalance)->buildSchedule(terms('5000000', '14.5', 240)), '5000000.00');
    });
});

describe('lease and ijarah', function () {
    it('leaves the residual value at the end', function () {
        $schedule = (new Lease)->buildSchedule(terms('3000000', '18', 48, residual: '300000'));

        expect((string) $schedule->residualValue)->toBe('300000.00');

        assertScheduleIsConsistent($schedule, '3000000.00');
    });

    it('prices like a reducing balance loan without a residual', function () {
        $lease = (new Lease)->buildSchedule(terms('100000', '24', 12));
        $loan = (new ReducingBalance)->buildSchedule(terms('100000', '24', 12));

        expect((string) $lease->installmentAmount())->toBe((string) $loan->installmentAmount());
    });

    it('rejects a residual value on a normal loan', function () {
        (new ReducingBalance)->buildSchedule(terms('100000', '24', 12, residual: '1000'));
    })->throws(InvalidArgumentException::class);

    it('uses the same arithmetic for ijarah rentals', function () {
        $schedule = (new Ijarah)->buildSchedule(terms('2000000', '16', 36, residual: '1'));

        assertScheduleIsConsistent($schedule, '2000000.00');
    });
});

describe('murabaha', function () {
    it('fixes the sale price at the contract', function () {
        $schedule = (new Murabaha)->buildSchedule(terms('100000', '24', 12));

        expect((string) $schedule->totalProfit())->toBe('24000.00')
            ->and((string) $schedule->totalPayable())->toBe('124000.00')
            ->and((string) $schedule->installmentAmount())->toBe('10333.33');

        assertScheduleIsConsistent($schedule, '100000.00');
    });

    it('recognises more profit early when more cost is outstanding', function () {
        $schedule = (new Murabaha)->buildSchedule(terms('100000', '24', 12));

        expect($schedule->lines[0]->profit->isGreaterThan($schedule->lines[10]->profit))->toBeTrue();
    });

    it('never produces a negative principal on long, high-rate terms', function () {
        // A simple rule-of-78 split goes negative here; the effective rate method does not.
        assertScheduleIsConsistent((new Murabaha)->buildSchedule(terms('500000', '24', 60)), '500000.00');
    });
});

describe('profit-free methods', function () {
    it('repays exactly the amount lent under qard hasan', function () {
        $schedule = (new QardHasan)->buildSchedule(terms('50000', '0', 10));

        expect((string) $schedule->totalProfit())->toBe('0.00')
            ->and((string) $schedule->installmentAmount())->toBe('5000.00');

        assertScheduleIsConsistent($schedule, '50000.00');
    });

    it('refuses a profit rate on qard hasan', function () {
        (new QardHasan)->buildSchedule(terms('50000', '5', 10));
    })->throws(InvalidArgumentException::class);

    it('repays a rahn advance in one payment at maturity', function () {
        $schedule = (new Rahn)->buildSchedule(terms('80000', '0', 6, RepaymentFrequency::Bullet));

        expect($schedule->lines)->toHaveCount(1)
            ->and($schedule->maturityDate()->toDateString())->toBe('2026-07-15')
            ->and((string) $schedule->totalPayable())->toBe('80000.00');
    });

    it('refuses a profit rate on rahn', function () {
        (new Rahn)->buildSchedule(terms('80000', '12', 6, RepaymentFrequency::Bullet));
    })->throws(InvalidArgumentException::class);
});

describe('gold pawning', function () {
    it('charges interest by actual days to maturity', function () {
        $schedule = (new GoldPawn)->buildSchedule(
            new LoanTerms('100000', '24', 6, RepaymentFrequency::Bullet, CarbonImmutable::parse('2026-01-01'))
        );

        // 1 Jan → 1 Jul 2026 = 181 days: 100,000 × 0.24 × 181 / 365 = 11,901.37
        expect($schedule->lines)->toHaveCount(1)
            ->and((string) $schedule->totalProfit())->toBe('11901.37')
            ->and($schedule->maturityDate()->toDateString())->toBe('2026-07-01');
    });

    it('accrues interest for early redemption', function () {
        $interest = GoldPawn::accruedInterest(
            Money::of('100000'), '24', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31')
        );

        expect((string) $interest)->toBe('1972.60');
    });

    it('must be a bullet loan', function () {
        (new GoldPawn)->buildSchedule(terms('100000', '24', 6));
    })->throws(InvalidArgumentException::class);
});

describe('pricing method rules', function () {
    it('keeps islamic and conventional methods apart', function () {
        expect(PricingMethod::Murabaha->isAllowedFor(FinanceBasis::Islamic))->toBeTrue()
            ->and(PricingMethod::Murabaha->isAllowedFor(FinanceBasis::Conventional))->toBeFalse()
            ->and(PricingMethod::ReducingBalance->isAllowedFor(FinanceBasis::Islamic))->toBeFalse()
            ->and(PricingMethod::forBasis(FinanceBasis::Islamic))->toBe([
                PricingMethod::Murabaha, PricingMethod::Ijarah, PricingMethod::QardHasan, PricingMethod::Rahn,
            ]);
    });

    it('maps every method to a calculator', function () {
        foreach (PricingMethod::cases() as $method) {
            expect($method->calculator())->toBeInstanceOf(PricingCalculator::class);
        }
    });
});

describe('loan terms', function () {
    it('rejects invalid input', function (string $principal, string $rate, int $tenure) {
        terms($principal, $rate, $tenure);
    })->with([
        'zero principal' => ['0', '10', 12],
        'negative rate' => ['1000', '-1', 12],
        'zero tenure' => ['1000', '10', 0],
    ])->throws(InvalidArgumentException::class);
});

<?php

declare(strict_types=1);

use App\Enums\RepaymentFrequency;
use App\Finance\Schedule\DueDateGenerator;
use App\Finance\Schedule\LoanTerms;
use Carbon\CarbonImmutable;

function dueDates(string $start, int $tenure, RepaymentFrequency $frequency = RepaymentFrequency::Monthly, ?string $firstDue = null): array
{
    $terms = new LoanTerms('1000', '10', $tenure, $frequency, CarbonImmutable::parse($start), $firstDue ? CarbonImmutable::parse($firstDue) : null);

    return array_map(fn ($date) => $date->toDateString(), (new DueDateGenerator)->generate($terms));
}

it('counts from the disbursement date', function () {
    expect(dueDates('2026-03-10', 3))->toBe(['2026-04-10', '2026-05-10', '2026-06-10']);
});

it('never overflows into the next month and never drifts', function () {
    // 31 Jan + 1 month must be 28 Feb (not 3 Mar), and March goes back to the 31st.
    expect(dueDates('2025-12-31', 4))->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
});

it('handles leap years', function () {
    expect(dueDates('2027-12-31', 3))->toBe(['2028-01-31', '2028-02-29', '2028-03-31']);
});

it('honours a fixed first due date', function () {
    expect(dueDates('2026-03-10', 3, firstDue: '2026-04-05'))->toBe(['2026-04-05', '2026-05-05', '2026-06-05']);
});

it('rejects a first due date before the start date', function () {
    dueDates('2026-03-10', 3, firstDue: '2026-03-01');
})->throws(InvalidArgumentException::class);

it('supports fortnightly installments', function () {
    expect(dueDates('2026-03-10', 3, RepaymentFrequency::Fortnightly))->toBe(['2026-03-24', '2026-04-07', '2026-04-21']);
});

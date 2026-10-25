<?php

declare(strict_types=1);

use App\Models\LoanTransaction;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

/*
| UTC storage, Sri Lankan business dates (module D). Between midnight and 05:30 in Colombo, UTC is
| still on the previous day: every business date must follow Colombo, every stored moment UTC,
| and the API keeps showing +05:30 as documented.
*/

it('uses the Sri Lankan calendar date while UTC is still on the previous day', function () {
    // 2026-10-21 01:00 in Colombo = 2026-10-20 19:30 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 19:30:00', 'UTC'));

    expect(BusinessDate::today()->toDateString())->toBe('2026-10-21')
        ->and(now()->toDateString())->toBe('2026-10-21');

    $branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);
    $loan = LoanFixtures::disbursedLoan($branchId, 'sme-loan', '120000', 12, disbursedOn: BusinessDate::todayImmutable());
    $cashier = Fixtures::staffInBranch($branchId, 'Loan Index', 'Payment Index', 'Payment Create');

    // "Today" in the validation rules is Colombo's today: a payment dated the 21st is accepted.
    $this->actingAs($cashier, 'api')->withHeader('Idempotency-Key', 'biz-date-key-01')
        ->postJson('/api/v1/payments', ['loan_public_id' => $loan->public_id, 'amount' => '1000', 'channel' => 'cash', 'value_date' => '2026-10-21'])
        ->assertCreated();

    // Without a value date the payment is booked on the business date, not the UTC date.
    $response = $this->actingAs($cashier, 'api')->withHeader('Idempotency-Key', 'biz-date-key-02')
        ->postJson('/api/v1/payments', ['loan_public_id' => $loan->public_id, 'amount' => '500', 'channel' => 'cash'])
        ->assertCreated();

    expect(LoanTransaction::where('public_id', $response->json('data.public_id'))->first()->value_date->toDateString())->toBe('2026-10-21');

    // One time zone everywhere: stored moments are Colombo time and the API shows them with +05:30.
    $disbursedAt = $this->actingAs(staffWith('Loan Index', 'Branch Data All'), 'api')->getJson("/api/v1/loans/{$loan->public_id}")
        ->assertOk()->json('data.dates.disbursed_at');

    expect($disbursedAt)->toEndWith('+05:30')
        ->and(CarbonImmutable::parse($disbursedAt)->toDateTimeString())->toBe(CarbonImmutable::parse(DB::table('loans')->where('id', $loan->id)->value('disbursed_at'), 'Asia/Colombo')->toDateTimeString());
});

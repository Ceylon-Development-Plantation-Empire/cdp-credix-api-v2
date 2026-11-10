<?php

declare(strict_types=1);

use App\Models\RecoveryCase;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\TemplateSeeder;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

/*
| GET /recovery-cases/{id}/activities: the record of calls, visits and promises to pay on a case.
*/

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class, TemplateSeeder::class]);
    Fixtures::fakeSms();
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);
});

it('lists what was done on a case, newest first, with the promise to pay', function () {
    LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(130));
    $this->artisan('eod:run')->assertSuccessful();
    $case = RecoveryCase::firstOrFail();

    $agent = Fixtures::staffInBranch($this->branchId, 'Recovery Case Work', 'Recovery Case Index');
    $this->actingAs($agent, 'api')->postJson("/api/v1/recovery-cases/{$case->public_id}/activities", ['type' => 'call', 'remarks' => 'No answer'])->assertCreated();
    $this->actingAs($agent, 'api')->postJson("/api/v1/recovery-cases/{$case->public_id}/activities", [
        'type' => 'promise_to_pay', 'remarks' => 'Will pay on Friday', 'promised_amount' => '15000', 'promised_date' => today()->addDays(3)->toDateString(),
    ])->assertCreated();

    $rows = $this->actingAs($agent, 'api')->getJson("/api/v1/recovery-cases/{$case->public_id}/activities")->assertOk()->json('data');

    $promise = collect($rows)->firstWhere('type', 'promise_to_pay');
    expect(collect($rows)->pluck('type'))->toContain('call', 'promise_to_pay')
        ->and($promise['promised_amount'])->toBe('15000.00')
        ->and($promise['performed_by']['id'])->toBe($agent->id);

    $this->actingAs(Fixtures::staffInBranch($this->branchId), 'api')->getJson("/api/v1/recovery-cases/{$case->public_id}/activities")->assertForbidden();
});

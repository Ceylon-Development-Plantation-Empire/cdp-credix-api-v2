<?php

declare(strict_types=1);

use App\Models\Collateral;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

beforeEach(function () {
    config([
        'services.cdp_connect.base_url' => 'https://cdp.example.test',
        'services.cdp_connect.api_key' => 'test-key',
        'services.cdp_core.inbound_key' => 'core-secret',
    ]);

    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);

    $this->officer = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Create', 'Loan Collateral Index', 'Loan Collateral Create', 'Loan Collateral Update', 'Loan Collateral Delete', 'Customer Index', 'CDP Customer Verification', 'Global Search');
    $this->borrower = LoanFixtures::customer($this->branchId, ['id_number' => '198534000937']);

    Http::fake(['cdp.example.test/*' => Http::response(['data' => [
        'customer' => ['full_name' => 'Piranya'],
        'summary' => ['total_invested' => '600000.00'],
        'investments' => [
            ['policy_number' => 'pol-1', 'investment_amount' => '600000', 'status' => 'approved', 'reservation_date' => '2026-01-01',
                'product' => ['name' => 'Fixed 36', 'duration_months' => 36, 'roi_percentage' => 12]],
            ['policy_number' => 'POL-2', 'investment_amount' => '100000', 'status' => 'pending', 'reservation_date' => '2026-01-01'],
        ],
    ]], 200)]);
});

function cdpLoan($test, $borrower = null, int $tenure = 24): string
{
    return $test->actingAs($test->officer, 'api')->postJson('/api/v1/loans', [
        'product_slug' => 'sme-loan', 'borrower_type' => 'individual', 'customer_public_id' => ($borrower ?? $test->borrower)->public_id,
        'requested_amount' => '150000', 'tenure' => $tenure, 'purpose' => 'Working capital',
    ])->assertCreated()->json('data.public_id');
}

function cdpPledge($test, string $loanId, $borrower = null, string $policy = 'POL-1')
{
    return $test->actingAs($test->officer, 'api')->postJson("/api/v1/loans/{$loanId}/collaterals/cdp-investment", [
        'nic' => ($borrower ?? $test->borrower)->normalizedIdNumber(), 'policy_number' => $policy,
    ]);
}

it('lists the approved policies of a customer, without the pending one', function () {
    $this->actingAs($this->officer, 'api')->getJson("/api/v1/customers/{$this->borrower->public_id}/investments")
        ->assertOk()
        ->assertJsonCount(1, 'data.investments')
        ->assertJsonPath('data.investments.0.policy_number', 'POL-1')
        ->assertJsonPath('data.investments.0.in_use', false);
});

it('looks up one policy and refuses a NIC that is not a borrower on the loan', function () {
    $this->actingAs($this->officer, 'api')->postJson('/api/v1/loan-securities/investment-lookup', [
        'nic' => $this->borrower->normalizedIdNumber(), 'policy_number' => 'pol-1', 'customer_public_ids' => [$this->borrower->public_id],
    ])->assertOk()->assertJsonPath('data.investment.investment_amount', '600000.00');

    $other = LoanFixtures::customer($this->branchId, ['id_number' => '199231303062']);
    $this->actingAs($this->officer, 'api')->postJson('/api/v1/loan-securities/investment-lookup', [
        'nic' => $other->normalizedIdNumber(), 'policy_number' => 'POL-1', 'customer_public_ids' => [$this->borrower->public_id],
    ])->assertStatus(422)->assertJsonPath('code', 'NIC_NOT_A_BORROWER');
});

it('pledges a policy as collateral with its value taken from CDP Core, and holds it for one loan only', function () {
    $loan = cdpLoan($this);

    $collateral = cdpPledge($this, $loan)->assertCreated()
        ->assertJsonPath('data.type', 'cdp_investment')
        ->assertJsonPath('data.market_value', '600000.00');

    $stored = Collateral::first();
    expect($stored->details['policy_number'])->toBe('POL-1')
        ->and($stored->details['investment']['product_name'])->toBe('Fixed 36')
        ->and($stored->details)->not->toHaveKey('id_number');

    // A pending policy cannot be pledged.
    cdpPledge($this, $loan, null, 'POL-2')->assertStatus(422)->assertJsonPath('code', 'POLICY_NOT_FOUND');

    // Another loan cannot take the same policy while the first is open.
    $other = LoanFixtures::customer($this->branchId, ['id_number' => '199231303062']);
    $second = cdpLoan($this, $other);
    cdpPledge($this, $second, $other)->assertStatus(409)->assertJsonPath('code', 'POLICY_ALREADY_HELD');

    // The picker shows who holds it.
    $this->actingAs($this->officer, 'api')->getJson("/api/v1/customers/{$other->public_id}/investments")
        ->assertJsonPath('data.investments.0.in_use', true);
});

it('refuses a policy that matures before the loan ends', function () {
    $loan = cdpLoan($this, null, 60);

    cdpPledge($this, $loan)->assertStatus(422)->assertJsonPath('code', 'POLICY_MATURES_TOO_EARLY');
});

it('tells CDP Core which policies are held, and only with its key', function () {
    cdpPledge($this, cdpLoan($this))->assertCreated();

    $this->getJson('/api/v1/external/core/policy-hold?policy_number=POL-1')->assertStatus(401);
    $this->withHeader('X-Core-Key', 'wrong')->getJson('/api/v1/external/core/policy-hold?policy_number=POL-1')->assertStatus(401);

    $this->withHeader('X-Core-Key', 'core-secret')->getJson('/api/v1/external/core/policy-hold?policy_number=pol-1')
        ->assertOk()->assertJsonPath('data.held', true)->assertJsonPath('data.loans.0.policy_number', 'POL-1');

    $this->withHeader('X-Core-Key', 'core-secret')->getJson('/api/v1/external/core/policy-hold?id_number='.$this->borrower->normalizedIdNumber())
        ->assertOk()->assertJsonPath('data.held', true);

    $this->withHeader('X-Core-Key', 'core-secret')->getJson('/api/v1/external/core/policy-hold?policy_number=NOPE')
        ->assertOk()->assertJsonPath('data.held', false);
});

it('finds a person and their loans across roles with the global search', function () {
    cdpLoan($this);

    $this->actingAs($this->officer, 'api')->getJson('/api/v1/global-search/id-types')->assertOk()->assertJsonPath('data.0', 'NIC');

    $this->actingAs($this->officer, 'api')->getJson('/api/v1/global-search?id_type=NIC&id_number='.$this->borrower->normalizedIdNumber())
        ->assertOk()
        ->assertJsonPath('data.found', true)
        ->assertJsonPath('data.summary.is_customer', true)
        ->assertJsonPath('data.summary.loans_as_borrower', 1)
        ->assertJsonPath('data.loans.0.role', 'customer');

    $this->actingAs($this->officer, 'api')->getJson('/api/v1/global-search?id_type=NIC&id_number=736813491V')
        ->assertOk()->assertJsonPath('data.found', false);

    $this->actingAs($this->officer, 'api')->getJson('/api/v1/global-search?id_type=Voter&id_number=1')->assertStatus(422);
});

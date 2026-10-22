<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\Collateral;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

/*
| Collateral types as data and lending-margin plans: head office adds kinds of security and named
| lending percentages without a release; an officer may pick a plan when pledging, nothing forces it.
*/

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);

    $this->headOffice = staffWith('Lookup Index', 'Lookup Manage', 'Lending Margin Index', 'Lending Margin Manage');
    $this->officer = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Create', 'Loan Collateral Manage');

    // A product that accepts property but does not require any collateral (nothing mandatory).
    $this->product = LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);
    $this->product->collateralRules()->create(['collateral_type' => 'property_mortgage', 'max_ltv_percent' => '65', 'is_mandatory' => false]);

    $this->borrower = LoanFixtures::customer($this->branchId);
    $this->loanId = $this->actingAs($this->officer, 'api')->postJson('/api/v1/loans', [
        'product_slug' => 'sme-loan', 'borrower_type' => 'individual', 'customer_public_id' => $this->borrower->public_id,
        'requested_amount' => '150000', 'tenure' => 24, 'purpose' => 'Stock',
    ])->assertCreated()->json('data.public_id');
});

function pledgeProperty($test, ?string $plan, string $value = '10000000')
{
    return $test->actingAs($test->officer, 'api')->postJson("/api/v1/loans/{$test->loanId}/collaterals", array_filter([
        'type' => 'property_mortgage',
        'lending_margin_plan' => $plan,
        'description' => 'House and land, Maharagama',
        'details' => ['deed_no' => 'D-4471', 'extent' => '20 perches', 'address' => '12 Temple Road, Maharagama'],
        'market_value' => $value,
        'valued_on' => today()->toDateString(),
    ], fn ($v) => $v !== null));
}

it('starts with the plans of the first system and lets head office add and re-price plans', function () {
    $this->actingAs($this->officer, 'api')->getJson('/api/v1/lending-margin-plans/options?collateral_type=property_mortgage')
        ->assertOk()->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.code', 'CDP-PRO-001')->assertJsonPath('data.0.percentage', '70.00');

    // Only the type, the name and the percentage are needed; the code is made from the name.
    $this->actingAs($this->headOffice, 'api')->postJson('/api/v1/lending-margin-plans', [
        'collateral_type' => 'property_mortgage', 'name' => 'Property Mortgage Plan 4', 'percentage' => '55',
    ])->assertCreated()->assertJsonPath('data.code', 'PROPERTY-MORTGAGE-PLAN-4')->assertJsonPath('data.percentage', '55.00');

    $this->actingAs($this->headOffice, 'api')->putJson('/api/v1/lending-margin-plans/PROPERTY-MORTGAGE-PLAN-4', ['percentage' => '57.50'])
        ->assertOk()->assertJsonPath('data.percentage', '57.50');
    $this->actingAs($this->headOffice, 'api')->putJson('/api/v1/lending-margin-plans/PROPERTY-MORTGAGE-PLAN-4', ['code' => 'X'])
        ->assertStatus(422);

    // The change is on the audit trail with the old and new percentage.
    $entry = ActivityLog::where('module', 'LendingMarginPlan')->where('action', 'UPDATE')->get()->first(fn ($l) => isset($l->payload['old']['percentage']));
    expect((float) $entry->payload['old']['percentage'])->toBe(55.0)->and((float) $entry->payload['new']['percentage'])->toBe(57.5);

    // Branch staff cannot manage plans.
    $this->actingAs($this->officer, 'api')->postJson('/api/v1/lending-margin-plans', ['collateral_type' => 'vehicle', 'name' => 'X', 'percentage' => '10'])
        ->assertForbidden();
});

it('values a collateral at the chosen plan, or at the product rule when no plan is chosen', function () {
    // Plan 2 lends 50 %: 10,000,000 → 5,000,000.
    pledgeProperty($this, 'CDP-PRO-002')->assertCreated()
        ->assertJsonPath('data.lending_margin_plan.code', 'CDP-PRO-002')
        ->assertJsonPath('data.ltv_percent', '50.00')
        ->assertJsonPath('data.eligible_amount', '5000000.00');

    // No plan: the product's 65 %.
    $noPlan = pledgeProperty($this, null)->assertCreated()
        ->assertJsonPath('data.lending_margin_plan', null)
        ->assertJsonPath('data.eligible_amount', '6500000.00')->json('data.public_id');

    // Re-pricing a plan never changes a pledge already made.
    $this->actingAs($this->headOffice, 'api')->putJson('/api/v1/lending-margin-plans/CDP-PRO-002', ['percentage' => '40'])->assertOk();
    expect((string) Collateral::where('lending_margin_plan_id', '!=', null)->first()->eligible_amount)->toBe('5000000.00')
        ->and((string) Collateral::where('public_id', $noPlan)->first()->ltv_percent)->toBe('65.00');

    // A plan of another kind of security, or a switched-off plan, is refused.
    pledgeProperty($this, 'CDP-VEC-001')->assertStatus(422)->assertJsonPath('code', 'LENDING_MARGIN_PLAN_TYPE_MISMATCH');
    $this->actingAs($this->headOffice, 'api')->putJson('/api/v1/lending-margin-plans/CDP-PRO-003', ['is_active' => false])->assertOk();
    pledgeProperty($this, 'CDP-PRO-003')->assertStatus(422)->assertJsonPath('code', 'LENDING_MARGIN_PLAN_NOT_AVAILABLE');
});

it('lets head office add a new kind of collateral with its own fields, documents and plans', function () {
    $this->actingAs($this->headOffice, 'api')->postJson('/api/v1/lookups', [
        'type' => 'collateral_type', 'code' => 'machinery', 'label' => 'Machinery',
        'meta' => [
            'fields' => ['machine_no' => ['required', 'string', 'max:40'], 'make' => ['nullable', 'string', 'max:60'], 'colour' => []],
            'documents' => ['valuation-report'],
        ],
    ])->assertCreated()->assertJsonPath('data.meta.valuation', 'market_value')->assertJsonPath('data.meta.reference', 'registration');

    // Unsafe rules cannot be stored on a field.
    $this->actingAs($this->headOffice, 'api')->postJson('/api/v1/lookups', [
        'type' => 'collateral_type', 'code' => 'shares', 'label' => 'Shares', 'meta' => ['fields' => ['cds_no' => ['exists:users,email']]],
    ])->assertStatus(422);

    $this->actingAs($this->headOffice, 'api')->postJson('/api/v1/lending-margin-plans', ['collateral_type' => 'machinery', 'name' => 'Machinery Plan 1', 'percentage' => '40'])->assertCreated();
    $this->product->collateralRules()->create(['collateral_type' => 'machinery', 'max_ltv_percent' => '35']);

    $types = $this->actingAs($this->officer, 'api')->getJson('/api/v1/collateral-types')->assertOk()->json('data');
    $machinery = collect($types)->firstWhere('code', 'machinery');
    expect($machinery['fields']['machine_no']['required'])->toBeTrue()
        ->and($machinery['fields']['colour']['required'])->toBeFalse()
        ->and($machinery['documents'])->toBe(['valuation-report'])
        ->and($machinery['lending_margin_plans'][0]['percentage'])->toBe('40.00')
        ->and(collect($types)->pluck('code')->all())->toContain('gold', 'vehicle', 'property_mortgage');

    $pledge = fn (array $details, ?string $plan = null) => $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$this->loanId}/collaterals", array_filter([
        'type' => 'machinery', 'lending_margin_plan' => $plan, 'description' => 'Lathe', 'details' => $details,
        'market_value' => '2000000', 'valued_on' => today()->toDateString(),
    ]));

    $pledge(['make' => 'Colchester'])->assertStatus(422)->assertJsonFragment(['field' => 'details.machine_no']);
    $pledge(['machine_no' => 'LT-77'], 'MACHINERY-PLAN-1')->assertCreated()->assertJsonPath('data.eligible_amount', '800000.00')
        ->assertJsonPath('data.type_label', 'Machinery');
    $pledge(['machine_no' => 'LT-78'])->assertCreated()->assertJsonPath('data.eligible_amount', '700000.00');
});

it('keeps how a built-in collateral type is valued fixed, but lets head office relabel it', function () {
    $gold = $this->actingAs($this->headOffice, 'api')->getJson('/api/v1/lookups?type=collateral_type')->assertOk()
        ->collect('data')->firstWhere('code', 'gold');

    $this->actingAs($this->headOffice, 'api')->putJson("/api/v1/lookups/{$gold['id']}", ['meta' => ['valuation' => 'market_value']])
        ->assertStatus(422)->assertJsonPath('code', 'SYSTEM_COLLATERAL_TYPE_LOCKED');

    $this->actingAs($this->headOffice, 'api')->putJson("/api/v1/lookups/{$gold['id']}", ['label' => 'Gold jewellery', 'meta' => ['fields' => ['item' => ['required', 'string', 'max:100']], 'documents' => ['gold-valuation-slip']]])
        ->assertOk()->assertJsonPath('data.label', 'Gold jewellery')->assertJsonPath('data.meta.valuation', 'gold_weight');
});

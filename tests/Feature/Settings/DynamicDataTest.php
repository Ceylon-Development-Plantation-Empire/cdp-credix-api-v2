<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\Customer;
use Database\Seeders\ProductCatalogSeeder;
use Tests\Support\Fixtures;

/*
| What the business changes without a developer: code lists, custom fields and fee types
| (audit module E). Every change is audited.
*/

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->admin = staffWith('Lookup Index', 'Lookup Create', 'Lookup Update', 'Lookup Delete', 'Charge Type Index', 'Charge Type Create', 'Charge Type Update', 'Charge Type Delete', 'Product Create', 'Product Index', 'Branch Data All');
});

describe('code lists', function () {
    it('adds a bank that the bank-account form accepts at once, and audits the change', function () {
        $this->actingAs($this->admin, 'api')->postJson('/api/v1/lookups', ['type' => 'bank', 'code' => 'lanka_credit', 'label' => 'Lanka Credit Bank'])
            ->assertCreated()->assertJsonPath('data.code', 'lanka_credit');

        $this->actingAs(staffWith('Customer Index'), 'api')->getJson('/api/v1/customers/bank-options')
            ->assertOk()->assertJsonFragment(['Lanka Credit Bank']);

        expect(ActivityLog::where('module', 'Lookup')->where('action', 'CREATE')->exists())->toBeTrue();
    });

    it('serves dropdown options to any back-office user, and the admin list only with the permission', function () {
        $this->actingAs(staffWith(), 'api')->getJson('/api/v1/lookups/options/guarantor_relationship')
            ->assertOk()->assertJsonFragment(['code' => 'spouse', 'label' => 'Spouse']);

        $this->actingAs(staffWith(), 'api')->getJson('/api/v1/lookups?type=bank')->assertForbidden();
        $this->actingAs(staffWith(), 'api')->postJson('/api/v1/lookups', ['type' => 'bank', 'code' => 'x', 'label' => 'X'])->assertForbidden();
    });

    it('never lets the code of a value change, and keeps the same code unique per list', function () {
        $id = $this->actingAs($this->admin, 'api')->postJson('/api/v1/lookups', ['type' => 'guarantor_relationship', 'code' => 'neighbour', 'label' => 'Neighbour'])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->admin, 'api')->putJson("/api/v1/lookups/{$id}", ['code' => 'neighbor'])->assertStatus(422);
        $this->actingAs($this->admin, 'api')->putJson("/api/v1/lookups/{$id}", ['label' => 'Neighbour (next door)'])->assertOk();
        $this->actingAs($this->admin, 'api')->postJson('/api/v1/lookups', ['type' => 'guarantor_relationship', 'code' => 'neighbour', 'label' => 'Again'])->assertStatus(422);
    });

    it('refuses custom-field rules that could read the database', function () {
        $this->actingAs($this->admin, 'api')->postJson('/api/v1/lookups', [
            'type' => 'custom_field.customer', 'code' => 'spouse_name', 'label' => 'Spouse name',
            'meta' => ['rules' => ['nullable', 'exists:users,password']],
        ])->assertStatus(422)->assertJsonFragment(['field' => 'meta.rules.1']);
    });
});

describe('custom fields', function () {
    it('validates and stores a field the business defined, and drops undefined ones', function () {
        $this->actingAs($this->admin, 'api')->postJson('/api/v1/lookups', [
            'type' => 'custom_field.customer', 'code' => 'spouse_employer', 'label' => "Spouse's employer",
            'meta' => ['rules' => ['nullable', 'string', 'max:20']],
        ])->assertCreated();

        $officer = Fixtures::staffInBranch($this->branchId, 'Customer Create');
        $payload = [
            'branch_id' => $this->branchId, 'id_type' => 'nic', 'id_number' => '853400937V', 'full_name' => 'Kamal Perera',
            'name_with_initials' => 'K. Perera', 'date_of_birth' => '1985-12-05', 'phone_primary' => '0771234567',
            'address_line_1' => '12 Temple Road', 'city' => 'Maharagama', 'gn_division' => 'Maharagama West', 'ds_division' => 'Maharagama',
            'district' => 'Colombo', 'province' => 'Western', 'employment_status' => 'employed', 'occupation' => 'Teacher', 'employer_name' => 'MoE',
        ];

        $this->actingAs($officer, 'api')->postJson('/api/v1/customers', $payload + ['meta' => ['spouse_employer' => str_repeat('x', 30)]])
            ->assertStatus(422)->assertJsonFragment(['field' => 'meta.spouse_employer']);

        $this->actingAs($officer, 'api')->postJson('/api/v1/customers', $payload + ['meta' => ['spouse_employer' => 'Dialog', 'not_defined' => 'dropped']])
            ->assertCreated()->assertJsonPath('data.meta.spouse_employer', 'Dialog');

        expect(Customer::firstOrFail()->meta)->toBe(['spouse_employer' => 'Dialog']);
    });
});

describe('fee types', function () {
    it('adds a new upfront fee type that products can use, grouped with the other service charges on a quote', function () {
        $this->seed(ProductCatalogSeeder::class);

        $this->actingAs($this->admin, 'api')->postJson('/api/v1/charge-types', [
            'code' => 'legal_fee', 'name' => 'Legal fee', 'display_group' => 'Service charges',
            'allowed_calculations' => ['fixed'], 'allowed_recurrences' => ['once'],
        ])->assertCreated()->assertJsonPath('data.behaviour', 'upfront')->assertJsonPath('data.collections', ['deduct_from_disbursement', 'add_to_first_installment']);

        $this->actingAs($this->admin, 'api')->postJson('/api/v1/products', [
            'name' => 'Festival Advance', 'category_slug' => 'standard-borrowing', 'pricing_method' => 'flat',
            'default_rate' => '24', 'min_rate' => '18', 'max_rate' => '30', 'min_amount' => '10000', 'max_amount' => '500000',
            'min_tenure' => 3, 'max_tenure' => 24, 'repayment_frequency' => 'monthly',
            'charges' => [
                ['charge_type' => 'processing_fee', 'calculation' => 'percent_of_principal', 'value' => '1', 'collection' => 'deduct_from_disbursement'],
                ['charge_type' => 'legal_fee', 'calculation' => 'fixed', 'value' => '1500', 'collection' => 'deduct_from_disbursement'],
            ],
        ])->assertCreated();

        $quote = $this->actingAs($this->admin, 'api')->postJson('/api/v1/products/festival-advance/quote', ['amount' => '100000', 'tenure' => 12])->assertOk();

        // One combined line, with the itemised breakdown for a customer who asks.
        expect($quote->json('data.charge_breakdown'))->toBe([[
            'group' => 'Service charges',
            'total' => '2500.00',
            'items' => [
                ['charge_type' => 'processing_fee', 'name' => 'Processing fee', 'amount' => '1000.00'],
                ['charge_type' => 'legal_fee', 'name' => 'Legal fee', 'amount' => '1500.00'],
            ],
        ]])->and($quote->json('data.net_disbursement_amount'))->toBe('97500.00');
    });

    it('refuses a calculation the fee type does not allow, and keeps the engine types switched on', function () {
        $this->seed(ProductCatalogSeeder::class);
        $this->actingAs($this->admin, 'api')->postJson('/api/v1/charge-types', [
            'code' => 'legal_fee', 'name' => 'Legal fee', 'display_group' => 'Service charges',
            'allowed_calculations' => ['percent_of_overdue'], 'allowed_recurrences' => ['once'],
        ])->assertStatus(422);

        $this->actingAs($this->admin, 'api')->putJson('/api/v1/charge-types/late_payment', ['is_active' => false])->assertStatus(422);
        $this->actingAs($this->admin, 'api')->putJson('/api/v1/charge-types/late_payment', ['name' => 'Late charge', 'display_group' => 'Late charges'])
            ->assertOk()->assertJsonPath('data.name', 'Late charge');
    });
});

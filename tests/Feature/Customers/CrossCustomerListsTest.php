<?php

declare(strict_types=1);

use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

/*
| GET /guarantors and GET /customer-financials: lists across customers for the guarantor and asset screens.
*/

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);
    $this->officer = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Create', 'Loan Guarantor Index', 'Loan Guarantor Create', 'Loan Guarantor Update', 'Loan Guarantor Delete', 'Customer Index', 'Customer Financial Index', 'Customer Financial Create', 'Customer Financial Update', 'Customer Financial Delete');
    $this->kamal = LoanFixtures::customer($this->branchId, ['id_number' => '853400937V', 'date_of_birth' => '1985-12-05']);
    $this->nimal = LoanFixtures::customer($this->branchId, ['id_number' => '199012345678', 'date_of_birth' => '1990-01-01', 'gender' => 'male']);
});

it('lists every guarantee with the guarantor, the loan and who borrows', function () {
    $loan = $this->actingAs($this->officer, 'api')->postJson('/api/v1/loans', [
        'product_slug' => 'sme-loan', 'borrower_type' => 'individual', 'customer_public_id' => $this->nimal->public_id,
        'requested_amount' => '100000', 'tenure' => 12,
    ])->assertCreated()->json('data.public_id');
    $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$loan}/guarantors", ['customer_public_id' => $this->kamal->public_id])->assertCreated();

    $row = $this->actingAs($this->officer, 'api')->getJson('/api/v1/guarantors')
        ->assertOk()->assertJsonPath('data.meta.total', 1)->json('data.items.0');

    expect($row['customer']['public_id'])->toBe($this->kamal->public_id)
        ->and($row['loan']['public_id'])->toBe($loan)
        ->and($row['loan']['borrower']['public_id'])->toBe($this->nimal->public_id)
        ->and($row['loan']['status'])->toBe('draft')
        ->and($row['consented_at'])->toBeNull();

    // Filters: by guarantor, by loan status, by whether they are released.
    $this->actingAs($this->officer, 'api')->getJson("/api/v1/guarantors?customer_public_id={$this->nimal->public_id}")->assertOk()->assertJsonPath('data.meta.total', 0);
    $this->actingAs($this->officer, 'api')->getJson('/api/v1/guarantors?loan_status=approved')->assertOk()->assertJsonPath('data.meta.total', 0);
    $this->actingAs($this->officer, 'api')->getJson('/api/v1/guarantors?released=true')->assertOk()->assertJsonPath('data.meta.total', 0);
    $this->actingAs($this->officer, 'api')->getJson('/api/v1/guarantors?search=' . urlencode($this->kamal->full_name))->assertOk()->assertJsonPath('data.meta.total', 1);
});

it('needs the loan permission to list guarantors', function () {
    $this->actingAs(staffWith('Customer Index'), 'api')->getJson('/api/v1/guarantors')->assertStatus(403);
});

it('lists financial lines across customers, filtered by kind and category', function () {
    foreach ([[$this->kamal, 'vehicle', '2500000'], [$this->nimal, 'land', '9000000'], [$this->nimal, 'vehicle', '1200000']] as [$customer, $category, $amount]) {
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/customers/{$customer->public_id}/financials", [
            'kind' => 'asset', 'category' => $category, 'description' => "{$category} of {$customer->full_name}", 'amount' => $amount,
        ])->assertCreated();
    }
    $this->actingAs($this->officer, 'api')->postJson("/api/v1/customers/{$this->kamal->public_id}/financials", ['kind' => 'income', 'category' => 'salary', 'amount' => '90000'])->assertCreated();

    $this->actingAs($this->officer, 'api')->getJson('/api/v1/customer-financials')->assertOk()->assertJsonPath('data.meta.total', 4);
    $this->actingAs($this->officer, 'api')->getJson('/api/v1/customer-financials?kind=asset')->assertOk()->assertJsonPath('data.meta.total', 3);

    $vehicles = $this->actingAs($this->officer, 'api')->getJson('/api/v1/customer-financials?kind=asset&category=vehicle')
        ->assertOk()->assertJsonPath('data.meta.total', 2)->json('data.items');
    expect(collect($vehicles)->pluck('customer.public_id')->sort()->values()->all())->toBe(collect([$this->kamal->public_id, $this->nimal->public_id])->sort()->values()->all())
        ->and($vehicles[0])->toHaveKeys(['kind', 'category', 'amount', 'customer', 'is_verified']);

    $this->actingAs($this->officer, 'api')->getJson("/api/v1/customer-financials?customer_public_id={$this->nimal->public_id}")->assertOk()->assertJsonPath('data.meta.total', 2);
    $this->actingAs($this->officer, 'api')->getJson('/api/v1/customer-financials?category=nonsense')->assertOk()->assertJsonPath('data.meta.total', 0);
});

it('needs the customer permission to list financial lines', function () {
    $this->actingAs(staffWith('Loan Index'), 'api')->getJson('/api/v1/customer-financials')->assertStatus(403);
});

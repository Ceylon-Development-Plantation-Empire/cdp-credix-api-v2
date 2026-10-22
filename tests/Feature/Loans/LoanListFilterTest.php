<?php

declare(strict_types=1);

use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

/*
| GET /loans?customer_public_id= : one customer's loans across every page (the customer screen
| used to filter one page in the browser and missed the rest).
*/

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);
    $this->officer = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Create', 'Loan Guarantor Manage');
});

function draftFor($test, $customer, array $extra = []): string
{
    return $test->actingAs($test->officer, 'api')->postJson('/api/v1/loans', array_merge([
        'product_slug' => 'sme-loan', 'borrower_type' => 'individual', 'customer_public_id' => $customer->public_id,
        'requested_amount' => '100000', 'tenure' => 12,
    ], $extra))->assertCreated()->json('data.public_id');
}

it('lists the loans a customer borrows on, including as co-borrower, and nobody else\'s', function () {
    $kamal = LoanFixtures::customer($this->branchId, ['id_number' => '853400937V', 'date_of_birth' => '1985-12-05']);
    $nimal = LoanFixtures::customer($this->branchId, ['id_number' => '199012345678', 'date_of_birth' => '1990-01-01', 'gender' => 'male']);

    $own = draftFor($this, $kamal);
    $joint = draftFor($this, $nimal, ['borrower_type' => 'joint', 'co_borrower_public_ids' => [$kamal->public_id]]);
    draftFor($this, $nimal);

    $ids = $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans?customer_public_id={$kamal->public_id}&per_page=1&page=1")
        ->assertOk()->assertJsonPath('data.meta.total', 2)->json('data.items.*.public_id');

    expect($ids)->toHaveCount(1)->and([$own, $joint])->toContain($ids[0]);

    $this->actingAs($this->officer, 'api')->getJson('/api/v1/loans?customer_public_id=01JZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertOk()->assertJsonPath('data.meta.total', 0);
});

it('also lists the loans a customer only guarantees, with the role on each row (Customer 360)', function () {
    $kamal = LoanFixtures::customer($this->branchId, ['id_number' => '853400937V', 'date_of_birth' => '1985-12-05']);
    $nimal = LoanFixtures::customer($this->branchId, ['id_number' => '199012345678', 'date_of_birth' => '1990-01-01', 'gender' => 'male']);

    $own = draftFor($this, $kamal);
    $guaranteed = draftFor($this, $nimal);
    $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$guaranteed}/guarantors", ['customer_public_id' => $kamal->public_id])->assertCreated();
    draftFor($this, LoanFixtures::customer($this->branchId));

    $rows = $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans?customer_public_id={$kamal->public_id}")
        ->assertOk()->assertJsonPath('data.meta.total', 2)->json('data.items');

    expect(collect($rows)->pluck('customer_role', 'public_id')->all())->toBe([$guaranteed => 'guarantor', $own => 'borrower']);

    $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans?customer_public_id={$kamal->public_id}&role=guarantor")
        ->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.items.0.public_id', $guaranteed);

    $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans?customer_public_id={$kamal->public_id}&role=borrower")
        ->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.items.0.public_id', $own);
});

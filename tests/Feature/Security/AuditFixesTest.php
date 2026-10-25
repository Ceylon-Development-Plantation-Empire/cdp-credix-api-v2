<?php

declare(strict_types=1);

use Database\Seeders\ProductCatalogSeeder;
use Spatie\Permission\Models\Role;

/*
| Fixes from the connection audit: who may read product options, the full permission list, the role shape.
*/

it('lets whoever may create a loan read the product options and price a quote', function () {
    $this->seed(ProductCatalogSeeder::class);
    $officer = staffWith('Loan Create');

    $this->actingAs($officer, 'api')->getJson('/api/v1/products/options')->assertOk();
    $this->actingAs($officer, 'api')->postJson('/api/v1/products/sme-loan/quote', ['amount' => 100000, 'tenure' => 12])->assertOk();

    // The product list itself and a stranger stay closed.
    $this->actingAs($officer, 'api')->getJson('/api/v1/products')->assertForbidden();
    $this->actingAs(staffWith('Customer Index'), 'api')->getJson('/api/v1/products/options')->assertForbidden();
});

it('returns the whole permission list in one request', function () {
    $user = staffWith('Permission Index');
    foreach (range(1, 130) as $n) {
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => "Extra {$n}", 'guard_name' => 'api'], ['group_name' => 'Tests']);
    }

    $response = $this->actingAs($user, 'api')->getJson('/api/v1/permissions?per_page=500')->assertOk();

    expect(count($response->json('data.data')))->toBeGreaterThan(100)->and($response->json('data.per_page'))->toBe(500);
});

it('does not carry a per-role approval limit any more', function () {
    $role = Role::firstOrCreate(['name' => 'Loan Approver', 'guard_name' => 'api']);

    expect($role->fresh()->toArray())->not->toHaveKey('max_approval_amount');
});

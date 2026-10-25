<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures;

beforeEach(function () {
    config([
        'services.cdp_connect.base_url' => 'https://cdp.example.test',
        'services.cdp_connect.api_key' => 'test-key',
    ]);

    $this->branchId = Fixtures::branch();
    $this->officer = Fixtures::staffInBranch($this->branchId, 'CDP Customer Verification');
});

it('returns the customer, summary and investments from CDP Connect', function () {
    Http::fake(['cdp.example.test/*' => Http::response(['data' => [
        'customer' => ['full_name' => 'Piranya'],
        'summary' => ['total_invested' => '500000.00'],
        'investments' => [['policy_number' => 'POL-1']],
    ]], 200)]);

    $this->actingAs($this->officer, 'api')->postJson('/api/v1/cdp/verify-customer', ['id_number' => '200012345671', 'id_type' => 'nic'])
        ->assertOk()
        ->assertJsonPath('data.customer.full_name', 'Piranya')
        ->assertJsonPath('data.summary.total_invested', '500000.00')
        ->assertJsonCount(1, 'data.investments');

    Http::assertSent(fn ($request) => $request->hasHeader('X-Credix-Key', 'test-key')
        && $request['id_number'] === '200012345671' && $request['id_type'] === 'nic');
});

it('says so when CDP has no such customer, is not configured, or is down', function () {
    Http::fake(['cdp.example.test/*' => Http::sequence()
        ->push(['message' => 'Customer not found'], 404)
        ->push('boom', 500)]);

    $this->actingAs($this->officer, 'api')->getJson('/api/v1/cdp/verify-customer?id_number=736813491V')
        ->assertStatus(404)->assertJsonPath('code', 'CDP_CUSTOMER_NOT_FOUND');

    $this->actingAs($this->officer, 'api')->getJson('/api/v1/cdp/verify-customer?id_number=736813491V')
        ->assertStatus(502)->assertJsonPath('code', 'CDP_UNAVAILABLE');

    config(['services.cdp_connect.api_key' => '']);
    $this->actingAs($this->officer, 'api')->getJson('/api/v1/cdp/verify-customer?id_number=736813491V')
        ->assertStatus(503)->assertJsonPath('code', 'CDP_NOT_CONFIGURED');
});

it('needs the permission and a valid id type', function () {
    $other = Fixtures::staffInBranch($this->branchId, 'Customer Index');
    $this->actingAs($other, 'api')->getJson('/api/v1/cdp/verify-customer?id_number=736813491V')->assertStatus(403);

    $this->actingAs($this->officer, 'api')->getJson('/api/v1/cdp/verify-customer?id_number=736813491V&id_type=Passport')->assertStatus(422);
});

<?php

use App\Models\Country;
use App\Models\Province;
use App\Models\Region;
use App\Models\Zonal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = staffWith(
        'Country Create', 'Country Index', 'Province Create', 'Province Index', 'Province Update',
        'Zonal Create', 'Region Create', 'Branch Create', 'Group Create',
    );
});

it('serves the dropdown lists instead of treating "list" as an id', function () {
    Country::create(['name' => 'Sri Lanka', 'code' => 'LK']);

    $this->actingAs(staffWith(), 'api')->getJson('/api/v1/countries/list')->assertOk()->assertJsonPath('data.0.code', 'LK');
    $this->actingAs(staffWith('Branch Index'), 'api')->getJson('/api/v1/branches/list')->assertOk();
});

it('keeps every level in org_units while the API keeps its own names', function () {
    $country = $this->actingAs($this->admin, 'api')->postJson('/api/v1/countries', ['name' => 'Sri Lanka', 'code' => 'LK'])
        ->assertCreated()->json('data');

    $province = $this->actingAs($this->admin, 'api')->postJson('/api/v1/provinces', ['name' => 'Western', 'code' => 'WP', 'country_id' => $country['id']])
        ->assertCreated()->assertJsonPath('data.country_id', $country['id'])->assertJsonPath('data.country.name', 'Sri Lanka')
        ->assertJsonMissingPath('data.parent_id')->json('data');

    $zone = $this->actingAs($this->admin, 'api')->postJson('/api/v1/zonals', ['name' => 'Colombo zone', 'code' => 'CZ', 'province_id' => $province['id']])
        ->assertCreated()->json('data');
    $region = $this->actingAs($this->admin, 'api')->postJson('/api/v1/regions', ['name' => 'Colombo region', 'code' => 'WP', 'zonal_id' => $zone['id']])
        ->assertCreated()->json('data');

    // One table, one row per level; codes are unique per level only (the region reuses "WP").
    expect(DB::table('org_units')->pluck('unit_type')->sort()->values()->all())->toBe(['country', 'province', 'region', 'zone'])
        ->and(Country::count())->toBe(1)
        ->and(Province::first()->country->code)->toBe('LK')
        ->and(Zonal::first()->regions()->count())->toBe(1)
        ->and(Region::first()->zonal_id)->toBe($zone['id']);

    $this->actingAs($this->admin, 'api')->getJson("/api/v1/provinces?country_id={$country['id']}")->assertOk()->assertJsonPath('data.total', 1);

    $this->actingAs($this->admin, 'api')->postJson('/api/v1/provinces', ['name' => 'Again', 'code' => 'WP', 'country_id' => $country['id']])
        ->assertStatus(422);

    // A unit of the wrong level is refused as a parent.
    $this->actingAs($this->admin, 'api')->postJson('/api/v1/zonals', ['name' => 'Bad', 'code' => 'BZ', 'province_id' => $region['id']])
        ->assertStatus(422);

    // A province with branches below it cannot be deleted from under them.
    DB::table('branches')->insert([
        'name' => 'Colombo', 'code' => 'COL', 'province_id' => $province['id'], 'zone_id' => $zone['id'], 'region_id' => $region['id'],
        'address_line1' => 'Main Street', 'city' => 'Colombo', 'phone_primary' => '0112345678', 'opening_date' => '2020-01-01', 'branch_type' => 'city',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(fn () => DB::table('org_units')->where('id', $province['id'])->delete())->toThrow(QueryException::class);
});

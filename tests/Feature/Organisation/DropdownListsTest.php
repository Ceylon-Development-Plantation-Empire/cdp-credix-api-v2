<?php

declare(strict_types=1);

use App\Models\Designation;
use Illuminate\Support\Facades\DB;

/*
| The organisation dropdowns: designations (all, and per department) and departments, with the
| designation level returned as its lookup code.
*/

it('serves the designation and department dropdowns with the level code', function () {
    $departmentId = DB::table('departments')->insertGetId(['name' => 'Credit', 'code' => 'CR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    Designation::create(['department_id' => $departmentId, 'name' => 'Credit Officer', 'code' => 'CO', 'level' => 'entry']);
    $user = staffWith('Designation Index', 'Department Index');

    $this->actingAs($user, 'api')->getJson('/api/v1/designations/list')->assertOk()
        ->assertJsonPath('data.0.name', 'Credit Officer')->assertJsonPath('data.0.level', 'entry');
    $this->actingAs($user, 'api')->getJson("/api/v1/departments/{$departmentId}/designations")->assertOk()
        ->assertJsonPath('data.0.level', 'entry');
    $this->actingAs($user, 'api')->getJson('/api/v1/departments/list')->assertOk()
        ->assertJsonPath('data.0.code', 'CR');
});

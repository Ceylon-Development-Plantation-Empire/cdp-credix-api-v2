<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
| Feature tests run against a fresh in-memory SQLite database (see phpunit.xml).
|
*/

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A back-office user holding exactly the given permissions (no role), so every test states
 * which permissions an action needs.
 */
function staffWith(string ...$permissions): User
{
    return userWith('staff', ...$permissions);
}

function userWith(string $type, string ...$permissions): User
{
    $user = User::factory()->create(['user_type' => $type]);

    foreach ($permissions as $permission) {
        // group_name is required by the starter's permissions table.
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api'], ['group_name' => 'Tests']);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->givePermissionTo($permissions);

    return $user;
}

<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Every back-office endpoint that declares a permission must refuse a staff user without it.
 * (The starter declared some as new Middleware('permission:X', ['only' => [...]]), which Laravel
 * reads as a method list of one array: the check never ran.)
 */
it('refuses staff without the permission on org, HR and audit endpoints', function (string $method, string $uri) {
    $nobody = staffWith();

    $this->actingAs($nobody, 'api')->json($method, $uri, ['name' => 'X', 'code' => 'X1'])->assertForbidden();
})->with([
    ['GET', '/api/v1/countries'],
    ['POST', '/api/v1/countries'],
    ['GET', '/api/v1/provinces'],
    ['POST', '/api/v1/zonals'],
    ['POST', '/api/v1/regions'],
    ['GET', '/api/v1/departments'],
    ['POST', '/api/v1/designations'],
    ['POST', '/api/v1/groups'],
    ['GET', '/api/v1/activity-logs'],
    ['GET', '/api/v1/employees/1/manager-history'],
]);

it('shows staff their notifications only with the notification permission', function () {
    $this->actingAs(staffWith(), 'api')->getJson('/api/v1/notifications')->assertForbidden();
    $this->actingAs(staffWith('Notification Index'), 'api')->getJson('/api/v1/notifications')->assertOk();
});

it('creates the first administrator only with a strong password typed in secretly', function () {
    Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'api']);

    $this->artisan('credix:create-admin', ['username' => 'opsadmin', 'email' => 'ops@cdp.lk'])
        ->expectsQuestion('Password (min 12 characters, mixed case, a number and a symbol)', 'password')
        ->expectsQuestion('Repeat the password', 'password')
        ->assertFailed();

    $this->artisan('credix:create-admin', ['username' => 'opsadmin', 'email' => 'ops@cdp.lk'])
        ->expectsQuestion('Password (min 12 characters, mixed case, a number and a symbol)', 'Str0ng!Passw0rd')
        ->expectsQuestion('Repeat the password', 'Str0ng!Passw0rd')
        ->assertSuccessful();

    expect(User::where('username', 'opsadmin')->first()->hasRole('Super Admin'))->toBeTrue();
});

it('keeps the login usable after ordinary API calls from the same address', function () {
    $user = staffWith('Customer Index');
    $user->forceFill(['username' => 'officer1', 'password' => bcrypt('Secret-pass-1')])->save();
    $user->assignRole(Role::firstOrCreate(['name' => 'Officer', 'guard_name' => 'api']));

    foreach (range(1, 8) as $i) {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    $this->postJson('/api/v1/login', ['login' => 'officer1', 'password' => 'Secret-pass-1'])->assertOk();

    // Guessing one user's password is still limited: 5 tries a minute, the good one included.
    foreach (range(1, 4) as $i) {
        $this->postJson('/api/v1/login', ['login' => 'officer1', 'password' => 'wrong'])->assertUnauthorized();
    }
    $this->postJson('/api/v1/login', ['login' => 'officer1', 'password' => 'wrong'])->assertStatus(429);
});

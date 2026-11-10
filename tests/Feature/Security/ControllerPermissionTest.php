<?php

declare(strict_types=1);

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
    $user->forceFill(['email' => 'officer1@cdp.lk', 'password' => bcrypt('Secret-pass-1')])->save();
    $user->assignRole(Role::firstOrCreate(['name' => 'Officer', 'guard_name' => 'api']));

    foreach (range(1, 8) as $i) {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    $this->postJson('/api/v1/login', ['login' => 'officer1@cdp.lk', 'password' => 'Secret-pass-1'])->assertOk();

    // Guessing one user's password is still limited: 5 tries a minute, the good one included.
    foreach (range(1, 4) as $i) {
        $this->postJson('/api/v1/login', ['login' => 'officer1@cdp.lk', 'password' => 'wrong'])->assertUnauthorized();
    }
    $this->postJson('/api/v1/login', ['login' => 'officer1@cdp.lk', 'password' => 'wrong'])->assertStatus(429);
});

it('sets the session cookie HttpOnly, for the token lifetime, Secure in production', function () {
    $user = staffWith('Customer Index');
    $user->forceFill(['email' => 'officer2@cdp.lk', 'password' => bcrypt('Secret-pass-2')])->save();
    $user->assignRole(Role::firstOrCreate(['name' => 'Officer', 'guard_name' => 'api']));

    $local = $this->postJson('/api/v1/login', ['login' => 'officer2@cdp.lk', 'password' => 'Secret-pass-2'])->assertOk()->getCookie('auth_token', false);
    expect($local->isHttpOnly())->toBeTrue()
        ->and($local->isSecure())->toBeFalse()
        ->and($local->getExpiresTime() - time())->toBeLessThanOrEqual(config('jwt.ttl') * 60 + 5);

    app()->detectEnvironment(fn () => 'production');
    $production = $this->postJson('https://localhost/api/v1/login', ['login' => 'officer2@cdp.lk', 'password' => 'Secret-pass-2'])->assertOk()->getCookie('auth_token', false);
    expect($production->isSecure())->toBeTrue();
});

it('lets staff change their own password only with the current one and a strong new one', function () {
    $user = staffWith();
    $user->forceFill(['password' => bcrypt('Temp-pass-123')])->save();

    $this->actingAs($user, 'api')->postJson('/api/v1/password/change', ['current_password' => 'wrong', 'password' => 'New-Strong-pass-9', 'password_confirmation' => 'New-Strong-pass-9'])
        ->assertStatus(422)->assertJsonPath('code', 'CURRENT_PASSWORD_WRONG');
    $this->actingAs($user, 'api')->postJson('/api/v1/password/change', ['current_password' => 'Temp-pass-123', 'password' => 'weak', 'password_confirmation' => 'weak'])
        ->assertStatus(422);
    $this->actingAs($user, 'api')->postJson('/api/v1/password/change', ['current_password' => 'Temp-pass-123', 'password' => 'New-Strong-pass-9', 'password_confirmation' => 'New-Strong-pass-9'])
        ->assertOk();

    expect(Hash::check('New-Strong-pass-9', $user->fresh()->password))->toBeTrue();

    // The password change ended the old session (token version raised): sign in again with the current record.
    $this->actingAs($user->fresh(), 'api')->putJson('/api/v1/me', ['name' => 'Renamed Officer'])->assertOk()->assertJsonPath('data.user.name', 'Renamed Officer');
});

it('allows a busy screen far more than 60 API calls a minute', function () {
    $user = staffWith();

    foreach (range(1, 100) as $i) {
        $this->actingAs($user, 'api')->getJson('/api/v1/me')->assertOk();
    }
});

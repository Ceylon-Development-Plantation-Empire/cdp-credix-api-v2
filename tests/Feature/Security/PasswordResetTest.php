<?php

declare(strict_types=1);

use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Role;
use Tests\Support\Fixtures;

/*
| POST /forgot-password and POST /reset-password: a staff member who forgot the password gets an SMS code.
*/

function resettableUser(string $email = 'nadeeka@cdp.lk', ?string $phone = '0771234567'): User
{
    $user = staffWith('Customer Index');
    $user->forceFill(['email' => $email, 'password' => 'Correct-Horse-9!', 'phone' => $phone])->save();
    Role::firstOrCreate(['name' => 'Officer', 'guard_name' => 'api']);
    $user->assignRole('Officer');

    return $user;
}

it('sends a code to the mobile on record and sets the new password with it', function () {
    $sms = Fixtures::fakeSms();
    $user = resettableUser();
    $oldToken = JWTAuth::fromUser($user);

    $step = $this->postJson('/api/v1/forgot-password', ['email' => 'NADEEKA@cdp.lk'])->assertOk()->assertJsonStructure(['data' => ['challenge_id', 'expires_in']]);

    expect($sms->sent)->toHaveCount(1)->and($sms->sent[0]['message'])->toContain('password reset');

    $this->postJson('/api/v1/reset-password', [
        'challenge_id' => $step->json('data.challenge_id'), 'code' => '000000',
        'password' => 'Brand-New-Pass-42!', 'password_confirmation' => 'Brand-New-Pass-42!',
    ])->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');

    $this->postJson('/api/v1/reset-password', [
        'challenge_id' => $step->json('data.challenge_id'), 'code' => $sms->lastCode(),
        'password' => 'Brand-New-Pass-42!', 'password_confirmation' => 'Brand-New-Pass-42!',
    ])->assertOk();

    // The new password works, the old one does not, and an old session is over.
    $this->postJson('/api/v1/login', ['login' => 'nadeeka@cdp.lk', 'password' => 'Brand-New-Pass-42!'])->assertOk();
    $this->postJson('/api/v1/login', ['login' => 'nadeeka@cdp.lk', 'password' => 'Correct-Horse-9!'])->assertStatus(401);
    $this->withToken($oldToken)->getJson('/api/v1/me')->assertStatus(401);

    // A code is good once.
    $this->postJson('/api/v1/reset-password', [
        'challenge_id' => $step->json('data.challenge_id'), 'code' => $sms->lastCode(),
        'password' => 'Another-Pass-77!', 'password_confirmation' => 'Another-Pass-77!',
    ])->assertStatus(422);
});

it('answers the same for an unknown email and for an account with no mobile, and sends nothing', function () {
    $sms = Fixtures::fakeSms();
    resettableUser('nomobile@cdp.lk', null);

    $unknown = $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@cdp.lk'])->assertOk();
    $noMobile = $this->postJson('/api/v1/forgot-password', ['email' => 'nomobile@cdp.lk'])->assertOk();

    expect($sms->sent)->toBe([])
        ->and(array_keys($unknown->json('data')))->toBe(array_keys($noMobile->json('data')))
        ->and($unknown->json('message'))->toBe($noMobile->json('message'));

    // The challenge handed back opens nothing, and says what a wrong code says.
    $this->postJson('/api/v1/reset-password', [
        'challenge_id' => $unknown->json('data.challenge_id'), 'code' => '123456',
        'password' => 'Brand-New-Pass-42!', 'password_confirmation' => 'Brand-New-Pass-42!',
    ])->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
});

it('refuses a weak password and clears a lock when the reset succeeds', function () {
    $sms = Fixtures::fakeSms();
    $user = resettableUser();
    $user->forceFill(['locked_until' => now()->addHour(), 'failed_login_attempts' => 4])->save();

    $challenge = $this->postJson('/api/v1/forgot-password', ['email' => 'nadeeka@cdp.lk'])->assertOk()->json('data.challenge_id');

    $this->postJson('/api/v1/reset-password', ['challenge_id' => $challenge, 'code' => $sms->lastCode(), 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertStatus(422);

    $this->postJson('/api/v1/reset-password', [
        'challenge_id' => $challenge, 'code' => $sms->lastCode(), 'password' => 'Brand-New-Pass-42!', 'password_confirmation' => 'Brand-New-Pass-42!',
    ])->assertOk();

    expect($user->fresh()->isLocked())->toBeFalse()->and($user->fresh()->failed_login_attempts)->toBe(0);
});

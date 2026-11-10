<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Role;
use Tests\Support\Fixtures;

/*
| Sign-in, sessions and user administration (audit module B).
*/

beforeEach(function () {
    Mail::fake();
    Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'Officer', 'guard_name' => 'api']);
    $this->branchId = Fixtures::branch();
});

function signInUser(string $email, string $password): User
{
    $user = staffWith('Customer Index');
    $user->forceFill(['email' => $email, 'password' => $password])->save();
    $user->assignRole('Officer');

    return $user;
}

describe('sign-in', function () {
    it('signs in by email only, with the same answer for an unknown email and a wrong password', function () {
        signInUser('kamal@cdp.lk', 'Correct-Horse-9!');

        $this->postJson('/api/v1/login', ['login' => 'KAMAL@cdp.lk', 'password' => 'Correct-Horse-9!'])
            ->assertOk()->assertJsonPath('data.token_type', 'bearer')->assertJsonPath('data.must_change_password', false)
            ->assertCookie('auth_token');

        $unknown = $this->postJson('/api/v1/login', ['login' => 'nobody@cdp.lk', 'password' => 'x'])->assertUnauthorized();
        $wrong = $this->postJson('/api/v1/login', ['login' => 'kamal@cdp.lk', 'password' => 'x'])->assertUnauthorized();

        expect($unknown->json('message'))->toBe('Invalid credentials')->toBe($wrong->json('message'))
            ->and($unknown->json('code'))->toBe($wrong->json('code'))
            ->and(ActivityLog::where('action', 'LOGIN_FAILED')->count())->toBe(2)
            // SECURITY: the typed email is masked in the audit trail.
            ->and(ActivityLog::where('action', 'LOGIN_FAILED')->first()->payload['login'])->toBe('no****@cdp.lk');
    });

    it('locks the account after too many wrong passwords, across minutes', function () {
        Setting::create(['key' => 'security.login_max_attempts', 'group' => 'security', 'type' => 'integer', 'value' => '3', 'rules' => ['value' => 'required'], 'label' => 'x']);
        $user = signInUser('nimal@cdp.lk', 'Correct-Horse-9!');

        foreach (range(1, 3) as $i) {
            $this->travel(1)->minutes(); // past the per-minute rate limit
            $this->postJson('/api/v1/login', ['login' => 'nimal@cdp.lk', 'password' => 'wrong'])->assertUnauthorized();
        }

        // Even the right password is refused while locked.
        $this->postJson('/api/v1/login', ['login' => 'nimal@cdp.lk', 'password' => 'Correct-Horse-9!'])
            ->assertStatus(423)->assertJsonPath('code', 'ACCOUNT_LOCKED');
        expect($user->fresh()->isLocked())->toBeTrue();

        $this->travel(16)->minutes();
        $this->postJson('/api/v1/login', ['login' => 'nimal@cdp.lk', 'password' => 'Correct-Horse-9!'])->assertOk();
        expect($user->fresh()->failed_login_attempts)->toBe(0);
    });

    it('asks for an SMS code when staff 2FA is switched on', function () {
        $sms = Fixtures::fakeSms();
        Setting::create(['key' => 'security.staff_login_otp', 'group' => 'security', 'type' => 'boolean', 'value' => '1', 'rules' => ['value' => 'required'], 'label' => 'x']);
        $user = signInUser('sunil@cdp.lk', 'Correct-Horse-9!');
        $user->forceFill(['phone' => '0771234567'])->save();

        $step = $this->postJson('/api/v1/login', ['login' => 'sunil@cdp.lk', 'password' => 'Correct-Horse-9!'])
            ->assertStatus(202)->assertJsonPath('data.otp_required', true)->assertCookieMissing('auth_token');

        $this->postJson('/api/v1/login/verify-otp', ['challenge_id' => $step->json('data.challenge_id'), 'code' => '000000'])
            ->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');

        $this->postJson('/api/v1/login/verify-otp', ['challenge_id' => $step->json('data.challenge_id'), 'code' => $sms->lastCode()])
            ->assertOk()->assertCookie('auth_token');
    });
});

describe('sessions', function () {
    it('ends every other session when the password changes', function () {
        $user = signInUser('ruwan@cdp.lk', 'Correct-Horse-9!');
        $oldToken = JWTAuth::fromUser($user);

        $this->withToken($oldToken)->getJson('/api/v1/me')->assertOk();

        $this->withToken($oldToken)->postJson('/api/v1/password/change', [
            'current_password' => 'Correct-Horse-9!', 'password' => 'Brand-New-Pass-42!', 'password_confirmation' => 'Brand-New-Pass-42!',
        ])->assertOk()->assertCookie('auth_token');

        $this->withToken($oldToken)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'SESSION_EXPIRED');
        $this->withToken(JWTAuth::fromUser($user->fresh()))->getJson('/api/v1/me')->assertOk();
    });

    it('ends the sessions of a user an administrator deactivates', function () {
        $admin = userWith('admin', 'User Toggle Status');
        $admin->assignRole('Super Admin');
        $user = signInUser('saman@cdp.lk', 'Correct-Horse-9!');
        $token = JWTAuth::fromUser($user);

        $this->actingAs($admin, 'api')->patchJson("/api/v1/users/{$user->id}/toggle-status")->assertOk();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    });

    it('lets a user with a temporary password only change it while the setting forces it', function () {
        $user = signInUser('mala@cdp.lk', 'Correct-Horse-9!');
        $user->forceFill(['must_change_password' => true])->save();

        // Off (the default): the documented v2.0 flow is unchanged.
        $this->actingAs($user, 'api')->getJson('/api/v1/customers')->assertOk();

        Setting::create(['key' => 'security.force_password_change', 'group' => 'security', 'type' => 'boolean', 'value' => '1', 'rules' => ['value' => 'required'], 'label' => 'x']);
        $this->actingAs($user, 'api')->getJson('/api/v1/customers')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->actingAs($user, 'api')->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.must_change_password', true);
    });
});

describe('user administration', function () {
    it('never makes the NIC a password: a staff user without one gets a temporary password, shown once', function () {
        $admin = userWith('admin', 'User Create');
        $designation = Designation::create(['department_id' => DB::table('departments')->insertGetId(['name' => 'Credit', 'code' => 'CR', 'created_at' => now(), 'updated_at' => now()]), 'name' => 'Officer', 'code' => 'OFF', 'level' => 'entry']);

        $response = $this->actingAs($admin, 'api')->postJson('/api/v1/users', [
            'name' => 'Kasun Perera', 'email' => 'Kasun@cdp.lk', 'user_type' => 'staff', 'role' => 'Officer',
            'employee_code' => 'EMP001', 'id_number' => '199012345678', 'designation_id' => $designation->id, 'branch_id' => $this->branchId,
        ])->assertCreated();

        $temporary = $response->json('data.temporary_password');
        $user = User::where('email', 'kasun@cdp.lk')->firstOrFail();

        expect($temporary)->toBeString()->toHaveLength(20)
            ->and($user->must_change_password)->toBeTrue()
            ->and(Hash::check('199012345678', $user->password))->toBeFalse()
            ->and(Hash::check($temporary, $user->password))->toBeTrue()
            // The NIC is encrypted at rest and found through its blind index.
            ->and(DB::table('employees')->where('id', $user->employee_id)->value('id_number'))->not->toContain('199012345678')
            ->and(Employee::findByIdNumber('199012345678')?->id)->toBe($user->employee_id);

        // The same NIC cannot be used twice, even though it is encrypted.
        $this->actingAs($admin, 'api')->postJson('/api/v1/users', [
            'name' => 'Copy', 'email' => 'copy@cdp.lk', 'user_type' => 'staff', 'role' => 'Officer',
            'employee_code' => 'EMP002', 'id_number' => '199012345678', 'designation_id' => $designation->id,
        ])->assertStatus(422)->assertJsonFragment(['field' => 'id_number']);
    });

    it('lets only a Super Admin create an admin, and never with a weak password', function () {
        $manager = userWith('admin', 'User Create');
        $payload = ['name' => 'New Admin', 'email' => 'admin2@cdp.lk', 'user_type' => 'admin', 'role' => 'Officer', 'password' => 'Very-Strong-Pass-1!'];

        $this->actingAs($manager, 'api')->postJson('/api/v1/users', $payload)->assertForbidden();

        $manager->assignRole('Super Admin');
        $this->actingAs($manager, 'api')->postJson('/api/v1/users', [...$payload, 'password' => 'Short1!'])->assertStatus(422);
        $this->actingAs($manager, 'api')->postJson('/api/v1/users', $payload)->assertCreated()->assertJsonMissingPath('data.temporary_password');
    });
});

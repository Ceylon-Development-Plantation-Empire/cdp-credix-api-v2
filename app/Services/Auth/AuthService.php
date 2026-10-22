<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Exceptions\BusinessRuleException;
use App\Models\OtpChallenge;
use App\Models\Setting;
use App\Models\User;
use App\Services\Security\OtpService;
use App\Support\PhoneNormalizer;
use App\Traits\ActivityLogTrait;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

/**
 * Staff sign-in, sign-out and password changes.
 *
 * SECURITY, in one place:
 * - sign-in is by email + password only (owner decision); the starter's username / NIC fallback
 *   is gone;
 * - an unknown email costs the same as a wrong password (a dummy hash is checked), so response
 *   timing does not reveal which emails exist, and both answer the same "Invalid credentials";
 * - wrong passwords are counted per account across minutes; at security.login_max_attempts the
 *   account locks for security.login_lockout_minutes (the per-minute "login" rate limit applies too);
 * - every failed and successful sign-in is written to the audit trail (ActivityLogTrait);
 * - every token carries the user's token_version: a password change raises it, which ends every
 *   other session at once (EnsureTokenIsCurrent);
 * - with security.staff_login_otp on, the password only starts a second step: an SMS code sent
 *   through the senior's SmsService (OtpService).
 */
class AuthService
{
    use ActivityLogTrait;

    /**
     * A valid bcrypt hash of a random string, checked when the email is unknown so both paths cost the same.
     */
    private const DUMMY_HASH = '$2y$12$lua4ZMC6PyzH8eJoq9jYS.EEDR5zsSizZKVT529taaVmw/nG5lgC2';

    public function __construct(
        protected OtpService $otp,
    ) {}

    /**
     * Check the credentials. Returns the signed-in user and token, or (2FA on) the OTP challenge
     * the user must answer with POST /login/verify-otp.
     *
     * @return array{user: User, token: string}|array{challenge: OtpChallenge}
     *
     * @throws BusinessRuleException INVALID_CREDENTIALS (401), ACCOUNT_LOCKED (423), ACCOUNT_DISABLED (401), NO_ROLE (403), LOGIN_OTP_NO_PHONE (422)
     */
    public function attempt(string $login, string $password, ?string $ip): array
    {
        $email = mb_strtolower(trim($login));
        $user = User::query()->where('email', $email)->first();

        if ($user?->isLocked()) {
            $this->logActivity('LOGIN_LOCKED', 'Auth', 'Sign-in refused: account locked', ['user_id' => $user->id], 'warning');

            throw new BusinessRuleException('ACCOUNT_LOCKED', 'Too many wrong passwords. Try again later or ask an administrator to unlock the account.', 423);
        }

        // SECURITY: always run one bcrypt check (constant time whether or not the email exists).
        $valid = Hash::check($password, $user?->password ?? self::DUMMY_HASH) && $user !== null;

        if (! $valid) {
            $this->recordFailure($user, $email);

            throw new BusinessRuleException('INVALID_CREDENTIALS', 'Invalid credentials', 401);
        }

        $this->assertMaySignIn($user);

        if ((bool) Setting::value('security.staff_login_otp', false)) {
            return ['challenge' => $this->startOtp($user)];
        }

        return ['user' => $user, 'token' => $this->completeSignIn($user, $ip)];
    }

    /**
     * Second step of a sign-in with 2FA on.
     *
     * @return array{user: User, token: string}
     *
     * @throws BusinessRuleException OTP_* (422) when the code is not accepted.
     */
    public function verifyOtp(string $challengePublicId, string $code, ?string $ip): array
    {
        $challenge = OtpChallenge::query()
            ->where('public_id', $challengePublicId)
            ->where('purpose', OtpPurpose::StaffLogin->value)
            ->first()
            ?? throw new BusinessRuleException('OTP_NOT_FOUND', 'This sign-in code is not valid. Sign in again.', 422);

        $this->otp->verify($challenge, $code);

        /** @var User $user */
        $user = User::query()->findOrFail($challenge->subject_id);
        $this->assertMaySignIn($user);

        return ['user' => $user, 'token' => $this->completeSignIn($user, $ip)];
    }

    /**
     * Change the signed-in user's own password and end every other session.
     *
     * @return string A new token for this browser (the old one stops working with the version bump).
     *
     * @throws BusinessRuleException CURRENT_PASSWORD_WRONG (422)
     */
    public function changePassword(User $user, string $current, string $new, ?string $ip): string
    {
        if (! Hash::check($current, $user->password)) {
            $this->logActivity('PASSWORD_CHANGE_FAILED', 'User', 'Wrong current password on password change', null, 'warning');

            throw new BusinessRuleException('CURRENT_PASSWORD_WRONG', 'The current password is not correct.', 422);
        }

        DB::transaction(function () use ($user, $new) {
            $user->forceFill([
                'password' => $new,
                'password_changed_at' => now(),
                'must_change_password' => false,
                // SECURITY: every token issued before this moment is now refused.
                'token_version' => $user->token_version + 1,
            ])->save();
        });

        $this->logActivity('PASSWORD_CHANGE', 'User', 'Changed own password; other sessions ended');

        return $this->issueToken($user->fresh());
    }

    /**
     * Sign out this session (the token is blacklisted by the JWT package).
     */
    public function logout(): void
    {
        $this->logActivity('LOGOUT', 'Auth', 'Signed out');
        $this->guard()->logout();
    }

    /**
     * Reset the failure counter, stamp the sign-in and issue the token.
     */
    protected function completeSignIn(User $user, ?string $ip): string
    {
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();

        $this->logActivity('LOGIN', 'Auth', 'Signed in', ['user_id' => $user->id]);

        return $this->issueToken($user);
    }

    /**
     * A token for the user, carrying their current token_version (getJWTCustomClaims).
     */
    protected function issueToken(User $user): string
    {
        return $this->guard()->login($user);
    }

    /**
     * Count a wrong password and lock the account when the limit is reached.
     */
    protected function recordFailure(?User $user, string $email): void
    {
        // SECURITY: the typed email is masked in the audit entry (it may be someone else's address).
        $this->logActivity('LOGIN_FAILED', 'Auth', 'Sign-in failed', ['login' => self::maskEmail($email), 'user_id' => $user?->id], 'warning');

        if ($user === null) {
            return;
        }

        $attempts = $user->failed_login_attempts + 1;
        $max = (int) Setting::value('security.login_max_attempts', 10);

        if ($attempts >= $max) {
            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => now()->addMinutes((int) Setting::value('security.login_lockout_minutes', 15)),
            ])->save();

            $this->logActivity('ACCOUNT_LOCKED', 'Auth', "Account locked after {$max} wrong passwords", ['user_id' => $user->id], 'warning');

            return;
        }

        $user->forceFill(['failed_login_attempts' => $attempts])->save();
    }

    /**
     * Active, allowed to log in and holding at least one role.
     *
     * @throws BusinessRuleException
     */
    protected function assertMaySignIn(User $user): void
    {
        if (! $user->canLogin()) {
            throw new BusinessRuleException('ACCOUNT_DISABLED', 'Account is deactivated', 401);
        }

        if (! $user->roles()->exists()) {
            throw new BusinessRuleException('NO_ROLE', 'No admin role assigned. Please contact Super Admin.', 403);
        }
    }

    /**
     * Send the staff-login code.
     *
     * @throws BusinessRuleException LOGIN_OTP_NO_PHONE when the user has no mobile on record.
     */
    protected function startOtp(User $user): OtpChallenge
    {
        $phone = PhoneNormalizer::normalize($user->loginOtpPhone());

        if ($phone === null) {
            throw new BusinessRuleException('LOGIN_OTP_NO_PHONE', 'No mobile number is on record for this account. Ask an administrator to add one.', 422);
        }

        return $this->otp->issue(
            OtpPurpose::StaffLogin,
            $user,
            $phone,
            'CDP Capital: :code is your CrediX sign-in code. Valid :minutes minutes. Never share it with anyone.',
            null,
            null,
            null,
        );
    }

    /**
     * j.perera@cdp.lk → j.******@cdp.lk
     */
    private static function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return mb_substr($email, 0, 2).'***';
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 2).str_repeat('*', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }

    private function guard(): JWTGuard
    {
        /** @var JWTGuard $guard */
        $guard = Auth::guard('api');

        return $guard;
    }
}

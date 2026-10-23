<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyLoginOtpRequest;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Support\AuthCookie;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff sign-in, sign-out and "who am I" (API guide section 3).
 *
 * The JWT is set as the HttpOnly cookie "auth_token" and never returned in the body. Business
 * failures (wrong credentials, locked or disabled account, no role) are BusinessRuleExceptions
 * rendered as {status: "error", code, message}; the logic lives in AuthService.
 */
class AuthController extends Controller
{
    public function __construct(
        protected AuthService $auth,
    ) {}

    /**
     * POST /login {login: email, password}.
     *
     * 200 with the user and the session cookie; with SMS 2FA switched on (setting
     * security.staff_login_otp) 202 {otp_required, challenge_id, sent_to} instead, and the cookie is
     * set by POST /login/verify-otp.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->attempt($request->validated('login'), $request->validated('password'), $request->ip());

        if (isset($result['challenge'])) {
            return response()->json([
                'status' => 'success',
                'message' => 'Enter the code sent to your mobile',
                'data' => [
                    'otp_required' => true,
                    'challenge_id' => $result['challenge']->public_id,
                    'sent_to' => PhoneNormalizer::mask($result['challenge']->destination),
                    'expires_in' => (int) now()->diffInSeconds($result['challenge']->expires_at),
                ],
            ], 202);
        }

        return $this->signedIn($result['user'], $result['token'], $request, 'Login successful');
    }

    /**
     * POST /login/verify-otp {challenge_id, code}: second sign-in step when 2FA is on.
     */
    public function verifyOtp(VerifyLoginOtpRequest $request): JsonResponse
    {
        $result = $this->auth->verifyOtp($request->validated('challenge_id'), $request->validated('code'), $request->ip());

        return $this->signedIn($result['user'], $result['token'], $request, 'Login successful');
    }

    /**
     * POST /forgot-password {email}: send a reset code to the mobile on record. Always the same 200 answer.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $challengeId = $this->auth->requestPasswordReset($request->validated('email'));

        return response()->json([
            'status' => 'success',
            'message' => 'If the account can be reset, a code was sent to its registered mobile.',
            'data' => [
                'challenge_id' => $challengeId,
                'expires_in' => app(\App\Services\Security\OtpService::class)->expiryMinutes() * 60,
            ],
        ], 200);
    }

    /**
     * POST /reset-password {challenge_id, code, password, password_confirmation}.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->auth->resetPassword($request->validated('challenge_id'), $request->validated('code'), $request->validated('password'));

        return response()->json(['status' => 'success', 'message' => 'Password changed. Sign in with the new password.', 'data' => null], 200);
    }

    /**
     * POST /logout: end this session and remove the cookie.
     */
    public function logout(): JsonResponse
    {
        $this->auth->logout();

        return response()->json([
            'status' => 'success',
            'message' => 'Logout successful',
        ], 200)->withCookie(AuthCookie::forget());
    }

    /**
     * GET /me: the signed-in user with roles and permissions, and their branch.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('api');

        return response()->json([
            'status' => 'success',
            'message' => 'User details fetched successfully',
            'data' => [
                'user' => $this->withRolesAndPermissions($user),
                // The branch of the user's employee record: the default branch for new records.
                'branch' => $user->employee?->branch?->only(['id', 'name', 'code']),
                // Additive (guide v2.1): true when an administrator set the password.
                'must_change_password' => $user->must_change_password,
            ],
        ], 200);
    }

    /**
     * The documented sign-in response: user + token type + lifetime, token in the cookie only.
     */
    protected function signedIn(User $user, string $token, Request $request, string $message): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => [
                'user' => $this->withRolesAndPermissions($user),
                'token_type' => 'bearer',
                'expires_in' => (int) config('jwt.ttl') * 60,
                'must_change_password' => $user->must_change_password,
            ],
        ], 200)->cookie(AuthCookie::make($token, $request));
    }

    /**
     * The user with roles (id, name) and each role's permissions (id, name), pivots hidden.
     */
    protected function withRolesAndPermissions(User $user): User
    {
        $user->load(['roles' => fn ($query) => $query->select('id', 'name')
            ->with(['permissions' => fn ($q) => $q->select('id', 'name')])]);

        $user->roles->each(function ($role) {
            $role->makeHidden(['pivot']);
            $role->permissions->each->makeHidden(['pivot']);
        });

        return $user;
    }
}

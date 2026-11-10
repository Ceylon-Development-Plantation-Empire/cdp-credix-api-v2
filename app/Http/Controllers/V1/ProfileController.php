<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Services\Auth\AuthService;
use App\Support\AuthCookie;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\JsonResponse;

/**
 * The signed-in user's own account: name, email and password.
 *
 * There is no self-service "forgot password": staff passwords are reset by an administrator, and
 * the person then changes the temporary one here, proving it with the current password.
 */
class ProfileController extends Controller
{
    use ActivityLogTrait;

    public function __construct(
        protected AuthService $auth,
    ) {}

    /**
     * PUT /me {name?, email?}.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $before = $user->only(['name', 'email']);

        $user->update($request->validated());

        $this->logActivity('UPDATE', 'User', 'Updated own profile', ['before' => $before, 'after' => $user->only(['name', 'email'])]);

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated',
            'data' => ['user' => $user->only(['id', 'name', 'username', 'email', 'user_type'])],
        ], 200);
    }

    /**
     * POST /password/change {current_password, password, password_confirmation}.
     *
     * SECURITY: every other session ends (token version raised); this browser gets a fresh cookie
     * so the user stays signed in here.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $token = $this->auth->changePassword(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
            $request->ip(),
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Password changed',
        ], 200)->cookie(AuthCookie::make($token, $request));
    }
}

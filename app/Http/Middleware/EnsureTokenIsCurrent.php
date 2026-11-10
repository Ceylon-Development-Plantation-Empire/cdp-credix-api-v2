<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a token that was valid when issued but no longer is.
 *
 * SECURITY: a JWT cannot be recalled once issued, so each token carries the user's token_version
 * ("tv" claim). Changing the password (or an administrator deactivating the account, which also
 * raises the version) makes every older token fail here at once. A deactivated user is refused
 * even with a token whose version still matches.
 *
 * Runs right after the JWT "auth" middleware on every signed-in route.
 */
class EnsureTokenIsCurrent
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user('api');

        if ($user === null) {
            return $next($request);
        }

        // DECISION: read the live row (one primary-key lookup), never a user object cached by the
        // guard, so a password change or deactivation takes effect on the very next request.
        $current = User::query()->with('employee:id,is_active')->find($user->id);
        // The token sent with THIS request (header or cookie), not whatever the guard last held.
        $version = JWTAuth::setRequest($request)->parseToken()->getPayload()->get('tv');

        if ($current === null || (int) $version !== $current->token_version || ! $current->canLogin()) {
            auth('api')->invalidate(true);

            return response()->json([
                'status' => 'error',
                'code' => 'SESSION_EXPIRED',
                'message' => 'Your session has ended. Please sign in again.',
            ], 401);
        }

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While setting security.force_password_change is on, a user whose password was set by an
 * administrator (must_change_password) can only see /me, change the password or sign out.
 *
 * DECISION: the setting starts OFF so the integration built on API guide v2.0 keeps working;
 * the flag is already returned by /login and /me for the frontend to act on.
 */
class EnsurePasswordChanged
{
    /**
     * Routes still allowed before the password is changed (route URIs).
     */
    private const ALLOWED = ['api/v1/me', 'api/v1/password/change', 'api/v1/logout'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user('api');

        if ($user?->must_change_password
            && (bool) Setting::value('security.force_password_change', false)
            && ! in_array($request->route()?->uri(), self::ALLOWED, true)) {
            return response()->json([
                'status' => 'error',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
                'message' => 'Please choose a new password before continuing.',
            ], 403);
        }

        return $next($request);
    }
}

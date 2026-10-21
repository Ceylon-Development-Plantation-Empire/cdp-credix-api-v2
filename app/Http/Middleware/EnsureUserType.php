<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes a route group to every user type that is not listed.
 *
 * Usage: ->middleware('user.type:admin,staff')
 *
 * Permissions alone are not enough: an endpoint without a permission (a dropdown list, for
 * example) would otherwise be open to any logged-in user, including an external agency login.
 * The user type is checked from the database row, not from the token claims.
 */
class EnsureUserType
{
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $user = $request->user('api');

        if (! $user || ! in_array($user->user_type, $types, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not allowed to access this resource',
            ], 403);
        }

        return $next($request);
    }
}

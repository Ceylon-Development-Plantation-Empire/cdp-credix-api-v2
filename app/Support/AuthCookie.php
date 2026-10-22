<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The session cookie that carries the JWT (name "auth_token", read by the JWT middleware).
 *
 * SECURITY: HttpOnly (JavaScript cannot read the token, so an XSS bug cannot steal the session),
 * SameSite=Lax (not sent on cross-site POSTs), Secure in production and over HTTPS, and it lives
 * exactly as long as the token inside it.
 */
final class AuthCookie
{
    public const NAME = 'auth_token';

    /**
     * The cookie for a freshly issued token.
     */
    public static function make(string $token, Request $request): Cookie
    {
        return cookie(
            self::NAME,
            $token,
            (int) config('jwt.ttl', 60),
            '/',
            null,
            // Secure always in production and over HTTPS; plain-HTTP LAN testing only elsewhere.
            $request->isSecure() || app()->environment('production'),
            true,
            false,
            'lax'
        );
    }

    /**
     * An expired cookie that removes the session from the browser.
     */
    public static function forget(): Cookie
    {
        return cookie()->forget(self::NAME);
    }
}

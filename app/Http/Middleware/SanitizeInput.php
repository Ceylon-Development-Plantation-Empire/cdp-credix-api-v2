<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strips HTML tags from text input, so stored names, remarks and notes can never carry markup into
 * a screen that forgets to escape it (stored XSS defence in depth; the frontend escapes too).
 *
 * SECURITY: secrets and codes are left exactly as typed. Stripping "<" … ">" from a password would
 * silently change it (the user could then never sign in with what they typed), and OTP codes and
 * idempotency keys are compared byte for byte.
 */
class SanitizeInput
{
    /**
     * Keys never altered, at any depth.
     */
    private const UNTOUCHED = [
        'password',
        'password_confirmation',
        'current_password',
        'code',
        'idempotency_key',
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $input = $request->all();

        array_walk_recursive($input, function (&$item, $key) {
            if (is_string($item) && ! in_array($key, self::UNTOUCHED, true)) {
                $item = strip_tags($item);
            }
        });

        $request->merge($input);

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the endpoints CDP Core calls into CrediX (routes/v1.php, the external/core group).
 * Server to server, so no JWT: Core sends the shared key in X-Core-Key and it must match
 * CDP_CORE_INBOUND_KEY. The mirror of the X-Credix-Key that CdpConnectService sends Core.
 */
class VerifyCdpCoreKey
{
    public const HEADER = 'X-Core-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.cdp_core.inbound_key');

        if ($expected === '') {
            return response()->json([
                'status' => 'error',
                'code' => 'CDP_CORE_NOT_CONFIGURED',
                'message' => 'CDP Core access is not configured on this environment.',
            ], 503);
        }

        $given = (string) $request->header(self::HEADER, '');

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json([
                'status' => 'error',
                'code' => 'CDP_CORE_KEY_INVALID',
                'message' => 'Invalid or missing '.self::HEADER.' header.',
            ], 401);
        }

        return $next($request);
    }
}

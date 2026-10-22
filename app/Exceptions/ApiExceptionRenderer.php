<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * The one place API errors are turned into JSON (registered in bootstrap/app.php).
 *
 * Every error body has status "error" and a stable machine-readable code next to the message the
 * API guide documents (messages and existing keys are kept exactly, so integrations built on guide
 * v2.0 keep working; "status" / "code" are additive where they were missing). Business-rule
 * errors render themselves (BusinessRuleException); validation keeps both documented shapes.
 *
 * SECURITY: an unexpected exception never reaches the client as a message, trace or SQL text when
 * APP_DEBUG is off: it is logged (report) and the client gets a generic 500.
 */
final class ApiExceptionRenderer
{
    /**
     * Wire the renderers into the application's exception handler.
     */
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => self::isApi($request) || $request->expectsJson());

        // 401: no / invalid session.
        $exceptions->render(fn (AuthenticationException $e, Request $request) => self::isApi($request)
            ? self::json(401, 'UNAUTHENTICATED', 'Unauthenticated.', ['error' => 'Token not provided or invalid'])
            : null);

        $exceptions->render(fn (UnauthorizedHttpException $e, Request $request) => self::isApi($request)
            ? self::json(401, 'UNAUTHENTICATED', $e->getMessage() ?: 'Unauthenticated.', [], $e->getHeaders())
            : null);

        // SECURITY: the token library's internal message is not echoed (it can describe the token).
        $exceptions->render(fn (JWTException $e, Request $request) => self::isApi($request)
            ? self::json(401, 'TOKEN_INVALID', 'Token error', ['error' => 'Token not provided or invalid'])
            : null);

        // 403: signed in but not allowed (missing permission or a policy refusal).
        $exceptions->render(fn (UnauthorizedException $e, Request $request) => self::isApi($request)
            ? self::json(403, 'FORBIDDEN', 'User does not have the right permissions.')
            : null);

        $exceptions->render(fn (AuthorizationException $e, Request $request) => self::isApi($request)
            ? self::json(403, 'FORBIDDEN', $e->getMessage() ?: 'This action is unauthorized.')
            : null);

        // 404 / 405.
        $exceptions->render(fn (ModelNotFoundException $e, Request $request) => self::isApi($request)
            ? self::json(404, 'NOT_FOUND', 'Not found')
            : null);

        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => self::isApi($request)
            ? self::json(404, 'NOT_FOUND', $e->getPrevious() instanceof ModelNotFoundException ? 'Not found' : ($e->getMessage() ?: 'Not found'))
            : null);

        $exceptions->render(fn (MethodNotAllowedHttpException $e, Request $request) => self::isApi($request)
            ? self::json(405, 'METHOD_NOT_ALLOWED', 'This method is not allowed for this endpoint.', [], $e->getHeaders())
            : null);

        // 429: the Retry-After header is kept.
        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => self::isApi($request)
            ? self::json(429, 'TOO_MANY_REQUESTS', 'Too Many Attempts.', [], $e->getHeaders())
            : null);

        // 422 from Validator / $request->validate(): the documented {message, errors: {field: [...]}} shape.
        // (FormRequests of the finance modules answer [{field, messages}] themselves, also documented.)
        $exceptions->render(fn (ValidationException $e) => self::json(422, 'VALIDATION_FAILED', 'Validation failed', ['errors' => $e->errors()]));

        // Any other HTTP exception keeps its status.
        $exceptions->render(fn (HttpExceptionInterface $e, Request $request) => self::isApi($request)
            ? self::json($e->getStatusCode(), 'HTTP_'.$e->getStatusCode(), $e->getMessage() ?: 'Request failed', [], $e->getHeaders())
            : null);

        // SECURITY: last resort, never leak internals when debug is off.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! self::isApi($request) || config('app.debug') || $e instanceof BusinessRuleException) {
                return null;
            }

            return self::json(500, 'SERVER_ERROR', 'Internal server error');
        });
    }

    /**
     * The error envelope.
     *
     * @param  array<string, mixed>  $extra  Documented extra keys (errors, error).
     * @param  array<string, string>  $headers
     */
    private static function json(int $status, string $code, string $message, array $extra = [], array $headers = []): JsonResponse
    {
        return response()->json(['status' => 'error', 'code' => $code, 'message' => $message] + $extra, $status, $headers);
    }

    private static function isApi(Request $request): bool
    {
        return $request->is('api/*');
    }
}

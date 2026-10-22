<?php

declare(strict_types=1);

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureTokenIsCurrent;
use App\Http\Middleware\EnsureUserType;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\SanitizeInput;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPOpenSourceSaver\JWTAuth\Http\Middleware\Authenticate;
use PHPOpenSourceSaver\JWTAuth\Http\Middleware\RefreshToken;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every API request: HTTPS in production, HTML stripped from text input (secrets excepted),
        // security headers, and the per-user / per-address limit (named limiter "api").
        $middleware->api(append: [
            ForceHttps::class,
            SanitizeInput::class,
            SecurityHeaders::class,
            ThrottleRequests::class.':api',
        ]);

        $middleware->alias([
            // The starter's "auth" is the JWT guard middleware (token from the auth_token cookie or header).
            'auth' => Authenticate::class,
            'jwt.auth' => Authenticate::class,
            'jwt.refresh' => RefreshToken::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'sanitize.input' => SanitizeInput::class,
            'security.headers' => SecurityHeaders::class,
            'user.type' => EnsureUserType::class,
            // SECURITY: refuse tokens issued before a password change / deactivation (token_version).
            'token.current' => EnsureTokenIsCurrent::class,
            // Only while setting security.force_password_change is on (default off).
            'password.changed' => EnsurePasswordChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // One error envelope for the whole API ({status: "error", code, message, …}).
        ApiExceptionRenderer::register($exceptions);
    })->create();

<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

abstract class TestCase extends BaseTestCase
{
    /**
     * The starter's "auth" middleware is the JWT middleware, which reads the token from the
     * Authorization header. Laravel's default actingAs() only sets the user in memory, so here it
     * issues a real token instead: requests in tests go through exactly the same checks as in production.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        // fromUser() only signs a token; it does not log anyone in on the guard.
        return $this->withToken(JWTAuth::fromUser($user));
    }
}

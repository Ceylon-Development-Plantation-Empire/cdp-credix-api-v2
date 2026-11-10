<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Liveness check (no authentication, no database)
Route::get('/health-check', [HealthController::class, 'ping'])->name('api.health-check');

/* version 1 routes */
require __DIR__.'/v1.php';

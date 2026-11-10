<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Landing page
Route::get('/', [HealthController::class, 'landing'])->name('landing');

// Readiness check including the database
Route::get('/health', [HealthController::class, 'health'])->name('health');

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's database session store. The API itself is stateless (JWT in an HttpOnly cookie); this
 * table only backs the web routes (landing page, /health) when SESSION_DRIVER=database, as in envV2.
 *
 * DECISION: framework infrastructure (cache, jobs, sessions) is not counted against the
 * application table budget and keeps Laravel's stock shape, so framework upgrades stay painless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};

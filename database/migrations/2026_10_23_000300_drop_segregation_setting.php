<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Someone else must do this step" is gone: the permission, given through a role, alone decides who can act. The
 * setting that switched it on goes with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'approval.segregation_enabled')->delete();
        Cache::forget('setting:approval.segregation_enabled');
    }

    public function down(): void
    {
        // Nothing to restore: the rule no longer exists in the code.
    }
};

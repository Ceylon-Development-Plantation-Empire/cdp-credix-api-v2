<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The permission alone decides who can act: the "someone else must do this step" switch starts off.
 * It stays a setting, so it can be turned back on without a release.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'approval.segregation_enabled')->update(['value' => '0']);
        Cache::forget('setting:approval.segregation_enabled');
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'approval.segregation_enabled')->update(['value' => '1']);
        Cache::forget('setting:approval.segregation_enabled');
    }
};

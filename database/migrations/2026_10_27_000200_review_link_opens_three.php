<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The customer's review link may be opened three times (was two). Only a setting still at the old default is raised; a value
 * someone chose on purpose is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'loan_review.max_opens')->where('value', '2')->update(['value' => '3']);
        Cache::forget('setting:loan_review.max_opens');
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'loan_review.max_opens')->where('value', '3')->update(['value' => '2']);
        Cache::forget('setting:loan_review.max_opens');
    }
};

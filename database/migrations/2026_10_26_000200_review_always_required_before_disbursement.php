<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The customer's confirmation (the review link with the code, "OTP 2") is always required before a loan is paid out (owner decision
 * 26 Oct 2026). It is no longer a setting that can be switched off, so the setting goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'loan_review.required_before_disbursement')->delete();
        Cache::forget('setting:loan_review.required_before_disbursement');
    }

    public function down(): void
    {
        // The requirement stays on; there is nothing to restore.
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A loan is no longer held for an uploaded and verified signed agreement. What stands in for it is the customer's
 * own confirmation on the review link (with their code), so that switch starts on. It stays a setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'loan_review.required_before_disbursement')->update(['value' => '1']);
        Cache::forget('setting:loan_review.required_before_disbursement');
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'loan_review.required_before_disbursement')->update(['value' => '0']);
        Cache::forget('setting:loan_review.required_before_disbursement');
    }
};

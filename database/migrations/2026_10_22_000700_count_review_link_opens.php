<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The review link is spent by opens, not by time: loan_parties.review_open_count counts the loads of
 * the page and setting loan_review.max_opens says how many are allowed. The setting of hours and the
 * "valid for N hours" wording of the SMS go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_parties', function (Blueprint $table) {
            $table->unsignedSmallInteger('review_open_count')->default(0)->after('review_viewed_at')->comment('Loads of the review page since the link was sent');
        });

        DB::table('settings')->where('key', 'loan_review.link_validity_hours')->delete();
        Cache::forget('setting:loan_review.link_validity_hours');

        DB::table('templates')
            ->where('slug', 'loan.review-link')->where('channel', 'sms')
            ->update(['body' => str_replace(' (valid {{valid_hours}} hours)', '', DB::table('templates')->where('slug', 'loan.review-link')->where('channel', 'sms')->value('body') ?? '')])
        ;
    }

    public function down(): void
    {
        Schema::table('loan_parties', function (Blueprint $table) {
            $table->dropColumn('review_open_count');
        });
    }
};

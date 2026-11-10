<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two owner decisions (26 Oct 2026):
 *
 * 1. The application date is chosen by the officer (a customer may come back days later to finish an application
 *    that was started earlier). created_at stays the real moment the record was saved; application_date is the
 *    business date reports and lists use.
 *
 * 2. One open loan per customer is checked from the first step, drafts included, so an officer is told before filling
 *    in a second application. A person with the permission "Loan Multiple Open Allow" can still give a customer more
 *    than one loan, with a written reason, and the loan carries who allowed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->date('application_date')->nullable()->after('tenure')->index();
            $table->foreignId('multiple_open_by')->nullable()->after('application_date')->constrained('users')->restrictOnDelete();
            $table->string('multiple_open_reason', 500)->nullable()->after('multiple_open_by');
            $table->timestamp('multiple_open_at')->nullable()->after('multiple_open_reason');
        });

        // Loans already recorded were applied for the day they were saved.
        DB::table('loans')->whereNull('application_date')->update(['application_date' => DB::raw('DATE(created_at)')]);

        $this->settings();
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('multiple_open_by');
            $table->dropColumn(['application_date', 'multiple_open_reason', 'multiple_open_at']);
        });
    }

    private function settings(): void
    {
        // How far back an application date may be set.
        if (! DB::table('settings')->where('key', 'loan.application_backdate_days')->exists()) {
            DB::table('settings')->insert([
                'key' => 'loan.application_backdate_days',
                'group' => 'loans',
                'type' => 'integer',
                'value' => '30',
                'rules' => json_encode(['value' => 'required|integer|min:0|max:365']),
                'label' => 'Application date can go back (days)',
                'description' => 'The officer may set the application date up to this many days before today (a customer who came back to finish an application). Never in the future.',
                'is_custom' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // The one-open-loan rule now starts at the draft. A value someone already chose on purpose is kept only if it is not the old default.
        $scope = DB::table('settings')->where('key', 'loan.open_loan_scope')->first();

        if ($scope) {
            DB::table('settings')->where('key', 'loan.open_loan_scope')->update([
                'rules' => json_encode(['value' => 'required|in:draft,submitted,approved']),
                'description' => 'From which status a loan counts as open for the "one open loan per person" rule. draft = an application in progress already blocks a second one (the officer is told at the first step); submitted = only after it is submitted; approved = only after approval.',
                'value' => $scope->value === 'submitted' ? 'draft' : $scope->value,
                'updated_at' => now(),
            ]);
        }
    }
};

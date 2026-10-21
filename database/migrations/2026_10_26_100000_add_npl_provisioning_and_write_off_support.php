<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Non-performing loans, provisioning and write-off:
     * - a loan past gl.suspend_income_dpd stops earning: its interest / profit goes to "in suspense"
     *   (income_suspended_at is set) until the arrears are cleared;
     * - a monthly provision for loan losses by days past due (provision.rates);
     * - a written-off loan is closed in the books but recovery goes on; money recovered afterwards
     *   is income of its own.
     *
     * The suspense and provision accounts are contra-assets: they sit with the assets and carry a
     * credit balance that is netted against the receivables.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->date('income_suspended_at')->nullable()->after('risk_stage');
        });

        $now = now();

        DB::table('gl_accounts')->insert(array_map(fn (array $a) => [
            'code' => $a[0],
            'slug' => $a[1],
            'type' => $a[2],
            'name' => $a[3],
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            ['1150', 'interest-suspense', 'asset', 'Interest in suspense (contra)'],
            ['1160', 'profit-suspense', 'asset', 'Islamic profit in suspense (contra)'],
            ['1190', 'loan-loss-provision', 'asset', 'Provision for loan losses (contra)'],
            ['4300', 'bad-debt-recovered', 'income', 'Recoveries of loans written off'],
            ['5200', 'provision-expense', 'expense', 'Loan loss provision expense'],
        ]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('gl_accounts')->whereIn('slug', ['interest-suspense', 'profit-suspense', 'loan-loss-provision', 'bad-debt-recovered', 'provision-expense'])->delete();

        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('income_suspended_at');
        });
    }
};

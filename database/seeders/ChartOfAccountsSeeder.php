<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\GlAccountType;
use App\Models\GlAccount;
use Illuminate\Database\Seeder;

/**
 * System reference data: the GL accounts the posting rules write to, found by slug.
 *
 * Accountants may rename or renumber them afterwards (firstOrCreate never overwrites); the slug is
 * what the code uses and never changes. Contra-asset accounts (suspense, provision) carry a credit
 * balance netted against the receivables.
 */
class ChartOfAccountsSeeder extends Seeder
{
    /**
     * code, slug, type, name.
     *
     * @var list<array{0: string, 1: string, 2: GlAccountType, 3: string}>
     */
    private const ACCOUNTS = [
        ['1000', 'cash-in-hand', GlAccountType::Asset, 'Cash in hand'],
        ['1010', 'bank', GlAccountType::Asset, 'Bank'],
        ['1100', 'loans-receivable', GlAccountType::Asset, 'Loans receivable (principal)'],
        ['1110', 'islamic-financing-receivable', GlAccountType::Asset, 'Islamic financing receivable (principal / cost)'],
        ['1150', 'interest-suspense', GlAccountType::Asset, 'Interest in suspense (contra)'],
        ['1160', 'profit-suspense', GlAccountType::Asset, 'Islamic profit in suspense (contra)'],
        ['1190', 'loan-loss-provision', GlAccountType::Asset, 'Provision for loan losses (contra)'],
        ['1200', 'interest-receivable', GlAccountType::Asset, 'Interest receivable'],
        ['1210', 'profit-receivable', GlAccountType::Asset, 'Islamic profit receivable'],
        ['1300', 'charges-receivable', GlAccountType::Asset, 'Fees and charges receivable'],
        ['1310', 'penalty-receivable', GlAccountType::Asset, 'Late charges receivable'],
        ['2100', 'charity-payable', GlAccountType::Liability, 'Charity payable (Islamic late charges)'],
        ['2200', 'customer-advances', GlAccountType::Liability, 'Customer advances'],
        ['4100', 'interest-income', GlAccountType::Income, 'Interest income'],
        ['4110', 'profit-income', GlAccountType::Income, 'Islamic profit income'],
        ['4200', 'fee-income', GlAccountType::Income, 'Fee and charge income'],
        ['4210', 'penalty-income', GlAccountType::Income, 'Late payment charge income'],
        ['4300', 'bad-debt-recovered', GlAccountType::Income, 'Recoveries of loans written off'],
        ['5100', 'loans-written-off', GlAccountType::Expense, 'Loans written off'],
        ['5200', 'provision-expense', GlAccountType::Expense, 'Loan loss provision expense'],
    ];

    /**
     * Seed the system accounts.
     */
    public function run(): void
    {
        foreach (self::ACCOUNTS as [$code, $slug, $type, $name]) {
            // slug is not mass assignable (system identity); set it explicitly on create.
            $account = GlAccount::query()->where('slug', $slug)->first();
            if ($account === null) {
                (new GlAccount(['code' => $code, 'name' => $name, 'type' => $type, 'is_active' => true]))
                    ->forceFill(['slug' => $slug])->save();
            }
        }
    }
}

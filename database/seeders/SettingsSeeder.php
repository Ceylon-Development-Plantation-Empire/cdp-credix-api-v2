<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Seed the system settings.
     *
     * firstOrCreate on the key: running the seeder again adds new settings but never overwrites
     * a value someone has already changed in production.
     */
    public function run(): void
    {
        $components = 'in:principal,profit,charge,penalty|distinct';

        $settings = [
            /* Loans */
            ['loan.open_loan_scope', 'loans', SettingType::String, 'draft', ['value' => 'required|in:draft,submitted,approved'],
                'One open loan rule starts at', 'From which status a loan counts as open for the "one open loan per person" rule. draft = an application in progress already blocks a second one (the officer is told at the first step); submitted = only after it is submitted; approved = only after approval.'],
            ['loan.application_backdate_days', 'loans', SettingType::Integer, '30', ['value' => 'required|integer|min:0|max:365'],
                'Application date can go back (days)', 'The officer may set the application date up to this many days before today (a customer who came back to finish an application). Never in the future.'],
            ['borrower.min_age', 'loans', SettingType::Integer, '18', ['value' => 'required|integer|min:18|max:70'],
                'Minimum borrower age', 'Age in years on the application date.'],
            ['borrower.max_age_at_maturity', 'loans', SettingType::Integer, '65', ['value' => 'required|integer|min:30|max:100'],
                'Maximum age at maturity', 'Borrower age in years on the last due date.'],
            ['loan.offer_validity_days', 'loans', SettingType::Integer, '30', ['value' => 'required|integer|min:1|max:180'],
                'Offer valid for (days)', 'An approved offer must be accepted by the borrowers (OTP) within this many days of approval.'],
            ['affordability.max_dsr_percent', 'loans', SettingType::Decimal, '60', ['value' => 'required|numeric|min:1|max:100'],
                'Maximum debt service ratio (%)', 'Used when the product does not set its own. (Existing obligations + new installment) / net income.'],

            /* Guarantors */
            ['guarantor.max_active_guarantees', 'guarantors', SettingType::Integer, '2', ['value' => 'required|integer|min:1|max:10'],
                'Open loans one person can guarantee', 'A guarantor can guarantee at most this many open loans at the same time.'],
            ['guarantor.block_after_invocation', 'guarantors', SettingType::Boolean, '1', ['value' => 'required|boolean'],
                'Block guarantor who paid for a borrower', 'A guarantor who paid an installment for a borrower cannot apply for a loan or give a new guarantee until that loan is settled (D-2).'],

            /* Repayment and penalty */
            ['repayment.grace_period_days', 'repayment', SettingType::Integer, '7', ['value' => 'required|integer|min:0|max:90'],
                'Grace period (days)', 'Days after the due date before an installment is overdue and penalised. A product can set its own.'],
            ['penalty.calculation', 'repayment', SettingType::String, 'percent_of_overdue', ['value' => 'required|in:fixed,percent_of_overdue'],
                'Default penalty method', 'fixed = a rupee amount; percent_of_overdue = a % of the unpaid installment (D-4). A product can set its own.'],
            ['penalty.value', 'repayment', SettingType::Decimal, '2', ['value' => 'required|numeric|min:0|max:100000'],
                'Default penalty value', 'Rupees when the method is fixed, percent when it is percent_of_overdue.'],
            ['penalty.recurrence', 'repayment', SettingType::String, 'monthly_while_overdue', ['value' => 'required|in:once,monthly_while_overdue'],
                'Default penalty recurrence', 'once = one penalty per installment; monthly_while_overdue = again every full month it stays unpaid.'],
            ['payment.max_backdate_days', 'repayment', SettingType::Integer, '0', ['value' => 'required|integer|min:0|max:30'],
                'Payment back-dating allowed (days)', 'A payment dated further back needs an approval request.'],
            ['allocation.order.conventional', 'repayment', SettingType::Json, json_encode(['penalty', 'charge', 'profit', 'principal']),
                ['value' => 'required|array|size:4', 'value.*' => $components],
                'Payment order (conventional)', 'Order in which a payment settles the parts of an installment.'],
            ['allocation.order.islamic', 'repayment', SettingType::Json, json_encode(['profit', 'principal', 'charge', 'penalty']),
                ['value' => 'required|array|size:4', 'value.*' => $components],
                'Payment order (Islamic)', 'Order in which a payment settles the parts of an installment.'],

            /* Recovery */
            ['recovery.notice_dpd', 'recovery', SettingType::Integer, '90', ['value' => 'required|integer|min:1|max:365'],
                'Recovery notice after (days past due)', 'Recovery notification to borrower and guarantors (D-3).'],
            ['recovery.assign_agent_dpd', 'recovery', SettingType::Integer, '180', ['value' => 'required|integer|min:1|max:730'],
                'Assign recovery agent after (days past due)', 'An internal or external recovery agent is assigned (D-3).'],
            ['risk.stage_two_after_dpd', 'recovery', SettingType::Integer, '30', ['value' => 'required|integer|min:1|max:180'],
                'Stage 2 starts after (days past due)', 'SLFRS 9 staging.'],
            ['risk.stage_three_after_dpd', 'recovery', SettingType::Integer, '90', ['value' => 'required|integer|min:1|max:365'],
                'Stage 3 (non-performing) starts after (days past due)', 'SLFRS 9 staging.'],
            ['gl.suspend_income_dpd', 'accounting', SettingType::Integer, '90', ['value' => 'required|integer|min:30|max:365'],
                'Stop recognising interest after (days past due)', 'Interest / profit of a loan this far behind goes to suspense until its arrears are fully cleared.'],
            ['provision.rates', 'accounting', SettingType::Json, json_encode([
                ['min_dpd' => 0, 'percent' => '1'],
                ['min_dpd' => 31, 'percent' => '5'],
                ['min_dpd' => 91, 'percent' => '20'],
                ['min_dpd' => 181, 'percent' => '50'],
                ['min_dpd' => 361, 'percent' => '100'],
            ]), ['value' => 'required|array|min:1|max:20', 'value.*.min_dpd' => 'required|integer|min:0|max:3650|distinct', 'value.*.percent' => 'required|decimal:0,2|min:0|max:100'],
                'Loan loss provision rates', 'Percent of outstanding principal provided, by days past due. Posted monthly.'],
            ['write_off.min_dpd', 'accounting', SettingType::Integer, '360', ['value' => 'required|integer|min:90|max:3650'],
                'Write-off allowed after (days past due)', 'A loan can be put forward for write-off only this far behind.'],

            /* Gold loans */
            ['gold.max_renewals', 'gold', SettingType::Integer, '3', ['value' => 'required|integer|min:0|max:50'],
                'Renewals allowed per gold loan', 'After this many renewals the customer redeems the gold or takes a new loan. 0 = renewal not allowed.'],
            ['gold.renewal_max_dpd', 'gold', SettingType::Integer, '30', ['value' => 'required|integer|min:0|max:365'],
                'Renewal allowed until (days past maturity)', 'Later than this the loan cannot be renewed; it is redeemed or goes to recovery and auction.'],
            ['gold.rate_per_gram_24k', 'gold', SettingType::Decimal, null, ['value' => 'nullable|decimal:0,2|gt:0|max:9999999'],
                "Today's 24K gold rate per gram", 'Used to re-value pledged gold at renewal. Empty = the valuation made when the loan was given is used.'],

            /* KYC and OTP */
            ['kyc.validity_days', 'security', SettingType::Integer, '365', ['value' => 'required|integer|min:30|max:1095'],
                'KYC valid for (days)', 'After this the customer must confirm their details again.'],
            ['otp.expiry_minutes', 'security', SettingType::Integer, '5', ['value' => 'required|integer|min:1|max:30'],
                'OTP expiry (minutes)', null],
            ['otp.max_attempts', 'security', SettingType::Integer, '5', ['value' => 'required|integer|min:1|max:10'],
                'Wrong OTP attempts allowed', null],
            ['otp.max_sends', 'security', SettingType::Integer, '3', ['value' => 'required|integer|min:1|max:10'],
                'OTP resends allowed', null],

            /* Dashboard targets (0 = no target) */
            ['dashboard.target.disbursement', 'dashboard', SettingType::Decimal, '0', ['value' => 'required|numeric|min:0|max:100000000000'],
                'Monthly disbursement target (Rs)', 'Shown on the dashboard against what was paid out; a longer period scales it by the number of months. 0 = no target.'],
            ['dashboard.target.collection', 'dashboard', SettingType::Decimal, '0', ['value' => 'required|numeric|min:0|max:100000000000'],
                'Monthly collection target (Rs)', 'Shown on the dashboard against what was collected; a longer period scales it. 0 = no target.'],
            ['dashboard.target.approval_rate', 'dashboard', SettingType::Decimal, '0', ['value' => 'required|numeric|min:0|max:100'],
                'Approval rate target (%)', 'Approved ÷ (approved + rejected) in the period. 0 = no target.'],

            /* Customer review link */
            ['loan_review.max_opens', 'loans', SettingType::Integer, '2', ['value' => 'required|integer|min:1|max:100'],
                'Review link opens allowed', 'How many times the customer can open the SMS link (each page load counts). After that the branch sends a new link.'],

            /* Staff sign-in */
            ['security.login_max_attempts', 'security', SettingType::Integer, '10', ['value' => 'required|integer|min:3|max:50'],
                'Wrong passwords before an account locks', 'Counted across minutes; the per-minute login limit applies on top.'],
            ['security.login_lockout_minutes', 'security', SettingType::Integer, '15', ['value' => 'required|integer|min:1|max:1440'],
                'Account lock duration (minutes)', 'How long sign-in is refused after too many wrong passwords.'],
            ['security.staff_login_otp', 'security', SettingType::Boolean, '0', ['value' => 'required|boolean'],
                'SMS code at staff sign-in (2FA)', 'When on, staff and admins also enter a code sent to their mobile. Check every user has a mobile first.'],
            ['security.force_password_change', 'security', SettingType::Boolean, '0', ['value' => 'required|boolean'],
                'Force a new password after a reset', 'When on, a user whose password was set by an administrator can only change it before using the system.'],

            /* Notifications */
            ['notifications.due_reminder_days', 'notifications', SettingType::Integer, '3', ['value' => 'required|integer|min:0|max:30'],
                'Due reminder (days before)', 'SMS sent this many days before an installment is due. 0 turns it off.'],
            ['notifications.dedupe_minutes', 'notifications', SettingType::Integer, '5', ['value' => 'required|integer|min:0|max:1440'],
                'Duplicate SMS window (minutes)', 'The same message to the same number within this window is not sent twice.'],
            ['notifications.overdue_reminder_dpd', 'notifications', SettingType::Json, json_encode([1, 7, 30, 60]),
                ['value' => 'required|array|max:10', 'value.*' => 'integer|min:1|max:365|distinct'],
                'Overdue reminder days', 'Days past due on which an overdue reminder SMS is sent.'],
            ['company.name', 'notifications', SettingType::String, 'CDP Capital', ['value' => 'required|string|max:60'],
                'Company name in messages', 'Shown at the start of every SMS.'],

            /* Credit score */
            ['credit_score.base', 'credit_score', SettingType::Integer, '500', ['value' => 'required|integer|min:0|max:1000'],
                'Starting score', 'Score of a customer with no repayment history.'],
            ['credit_score.points_on_time', 'credit_score', SettingType::Integer, '10', ['value' => 'required|integer|min:0|max:100'],
                'Points for an installment paid on time', 'Added for each installment paid by the end of its grace period.'],
            ['credit_score.points_late', 'credit_score', SettingType::Integer, '10', ['value' => 'required|integer|min:0|max:100'],
                'Points for a late installment', 'Deducted for each installment paid after its grace period.'],
            ['credit_score.points_missed', 'credit_score', SettingType::Integer, '10', ['value' => 'required|integer|min:0|max:100'],
                'Points for an unpaid installment', 'Deducted for each installment still unpaid after its grace period.'],

            /* Frontend Form Lists (Bank list, group loan competency and service charge) */
            ['customer_bank_list', 'customers', SettingType::Json, json_encode([
                'Amana Bank', 'Bank of Ceylon', 'Bank of China', 'Cargills Bank', 'Citibank N.A.',
                'Commercial Bank of Ceylon', 'DFCC Bank', 'Deutsche Bank AG', 'HDFC Bank', 'HSBC',
                'Habib Bank', 'Hatton National Bank', 'Indian Bank', 'Indian Overseas Bank',
                'MCB Bank', 'National Development Bank', 'National Savings Bank', 'Nations Trust Bank',
                'Pan Asia Banking Corporation', "People's Bank", 'Pradeshiya Sanwardhana Bank',
                'Public Bank Berhad', 'Sampath Bank', 'Sanasa Development Bank', 'Seylan Bank',
                'Standard Chartered Bank', 'State Bank of India', 'State Mortgage and Investment Bank',
                'Union Bank of Colombo',
            ]), ['value' => 'required'],
                'Customer Bank List', 'Available Sri Lankan licensed commercial and specialised banks for disbursement.'],
            ['group_loan_competency', 'loans', SettingType::Json, json_encode([
                'Agriculture & Farming', 'Retail & Wholesale Trade', 'Fisheries & Livestock',
                'Handicrafts & Textiles', 'Food Processing & Bakeries', 'Transport & Delivery Services',
                'Personal & Commercial Services', 'Construction & Masonry', 'Small Manufacturing',
            ]), ['value' => 'required'],
                'Group Loan Member Competencies', 'Business activity categories for group loan competency appraisal.'],
            ['group_loan_service_charge_percentage', 'loans', SettingType::Decimal, '2.50', ['value' => 'required|numeric|min:0|max:100'],
                'Group Loan Service Charge (%)', 'Default service charge percentage for group microfinance loans.'],
        ];

        foreach ($settings as [$key, $group, $type, $value, $rules, $label, $description]) {
            Setting::firstOrCreate(['key' => $key], [
                'group' => $group,
                'type' => $type,
                'value' => $value,
                'rules' => $rules,
                'label' => $label,
                'description' => $description,
            ]);
        }
    }
}

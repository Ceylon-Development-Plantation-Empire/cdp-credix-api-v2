<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\NotificationEvent;
use App\Models\Template;
use Illuminate\Database\Seeder;

class TemplateSeeder extends Seeder
{
    /**
     * Global English SMS texts for every event. Sinhala and Tamil versions, and product-specific
     * wording, are added through the templates screen; English is the fallback until then.
     *
     * firstOrCreate: re-running never overwrites a text someone has edited.
     */
    public function run(): void
    {
        $texts = [
            NotificationEvent::LoanApproved->value => '{{company_name}}: Dear {{customer_name}}, your loan {{application_no}} of Rs {{amount}} is approved ({{tenure}} installments of Rs {{installment_amount}}). Please visit the branch to accept the offer.',
            NotificationEvent::LoanRejected->value => '{{company_name}}: Dear {{customer_name}}, we are unable to approve loan application {{application_no}} at this time. Please contact your branch for details.',
            NotificationEvent::LoanReviewLink->value => '{{company_name}}: Dear {{customer_name}}, please check your details and the terms of loan {{application_no}} and confirm them here: {{review_link}} (valid {{valid_hours}} hours). We will never ask for your code by phone.',
            NotificationEvent::LoanDisbursed->value => '{{company_name}}: Dear {{customer_name}}, loan {{loan_no}} of Rs {{amount}} is disbursed (Rs {{net_amount}} paid out). First installment of Rs {{installment_amount}} is due on {{first_due_date}}.',
            NotificationEvent::PaymentReceived->value => '{{company_name}}: Rs {{amount}} received for loan {{loan_no}} (ref {{txn_no}}). Balance Rs {{outstanding}}. Thank you.',
            NotificationEvent::LoanSettled->value => '{{company_name}}: Dear {{customer_name}}, loan {{loan_no}} is fully settled. Thank you for banking with us.',
            NotificationEvent::InstallmentDueReminder->value => '{{company_name}}: Reminder: Rs {{amount}} for loan {{loan_no}} is due on {{due_date}}.',
            NotificationEvent::InstallmentOverdue->value => '{{company_name}}: Dear {{customer_name}}, Rs {{arrears}} on loan {{loan_no}} is overdue ({{days_past_due}} days). Please pay to avoid further charges.',
            NotificationEvent::RecoveryNotice->value => '{{company_name}}: RECOVERY NOTICE. Loan {{loan_no}} is {{days_past_due}} days overdue with arrears of Rs {{arrears}}. Settle immediately or contact {{branch_name}} branch.',
            NotificationEvent::RecoveryNoticeGuarantor->value => '{{company_name}}: Dear {{customer_name}}, loan {{loan_no}} of {{borrower_name}}, which you guaranteed, is in arrears of Rs {{arrears}}. Please contact {{branch_name}} branch.',
        ];

        foreach ($texts as $slug => $body) {
            Template::firstOrCreate(
                ['slug' => $slug, 'channel' => 'sms', 'language' => 'en', 'product_id' => null, 'category_id' => null],
                ['body' => $body, 'is_active' => true],
            );
        }
    }
}

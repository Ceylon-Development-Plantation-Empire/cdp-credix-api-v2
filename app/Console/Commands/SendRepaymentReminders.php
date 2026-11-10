<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Enums\NotificationEvent;
use App\Enums\PartyRole;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanParty;
use App\Models\Setting;
use App\Services\Notifications\NotificationService;
use App\Support\BusinessDate;
use Illuminate\Console\Command;

/**
 * Morning SMS to borrowers:
 *   due      → N days before an installment is due (notifications.due_reminder_days, 0 = off)
 *   overdue  → on the days past due listed in notifications.overdue_reminder_dpd (e.g. 1, 7, 30, 60),
 *              so a borrower in arrears is reminded at intervals, not every day
 * The notification log's duplicate check stops a re-run from sending twice.
 */
class SendRepaymentReminders extends Command
{
    protected $signature = 'notifications:reminders {type : due or overdue}';

    protected $description = 'Send installment due reminders or overdue reminders to borrowers';

    public function handle(NotificationService $notifications): int
    {
        $sent = match ($this->argument('type')) {
            'due' => $this->due($notifications),
            'overdue' => $this->overdue($notifications),
            default => null,
        };

        if ($sent === null) {
            $this->error('Type must be "due" or "overdue".');

            return self::FAILURE;
        }

        $this->info("{$sent} reminder(s) queued.");

        return self::SUCCESS;
    }

    protected function due(NotificationService $notifications): int
    {
        $days = (int) Setting::value('notifications.due_reminder_days', 3);

        if ($days <= 0) {
            return 0;
        }

        $sent = 0;

        LoanInstallment::query()
            ->with(['loan' => fn ($q) => $q->withoutGlobalScope('branch')])
            ->whereIn('status', [InstallmentStatus::Upcoming->value, InstallmentStatus::PartiallyPaid->value])
            ->whereDate('due_date', BusinessDate::today()->addDays($days))
            ->whereHas('loan', fn ($q) => $q->withoutGlobalScope('branch')->where('status', LoanStatus::Active->value))
            ->chunkById(200, function ($installments) use ($notifications, &$sent) {
                foreach ($installments as $installment) {
                    foreach ($this->borrowers($installment->loan) as $party) {
                        $notifications->notifyCustomer(NotificationEvent::InstallmentDueReminder, $party->customer, [
                            'loan_no' => $installment->loan->loan_no,
                            'amount' => (string) $installment->unpaidTotal(),
                            'due_date' => $installment->due_date->toDateString(),
                        ], $installment->loan);
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    protected function overdue(NotificationService $notifications): int
    {
        $dpdList = array_map('intval', (array) Setting::value('notifications.overdue_reminder_dpd', [1, 7, 30, 60]));
        $sent = 0;

        Loan::query()
            ->withoutGlobalScope('branch')
            ->where('status', LoanStatus::Active->value)
            ->whereIn('days_past_due', $dpdList)
            ->chunkById(200, function ($loans) use ($notifications, &$sent) {
                foreach ($loans as $loan) {
                    if ($loan->isGroupFacility()) {
                        continue;
                    }

                    foreach ($this->borrowers($loan) as $party) {
                        $notifications->notifyCustomer(NotificationEvent::InstallmentOverdue, $party->customer, [
                            'loan_no' => $loan->loan_no,
                            'arrears' => (string) $loan->arrears_amount,
                            'days_past_due' => $loan->days_past_due,
                        ], $loan);
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    protected function borrowers(Loan $loan)
    {
        return LoanParty::with('customer')->where('loan_id', $loan->id)->whereIn('role', PartyRole::borrowingValues())->get();
    }
}

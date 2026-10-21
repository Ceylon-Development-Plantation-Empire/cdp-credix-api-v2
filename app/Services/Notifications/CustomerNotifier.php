<?php

namespace App\Services\Notifications;

use App\Enums\LoanStatus;
use App\Enums\NotificationEvent;
use App\Enums\PartyRole;
use App\Enums\TransactionType;
use App\Events\LoanStatusChanged;
use App\Events\PaymentPosted;
use App\Events\RecoveryCaseOpened;
use App\Finance\Support\Money;
use App\Models\Loan;
use App\Models\LoanParty;
use Illuminate\Support\Collection;

/**
 * Turns domain events into customer messages. Registered in AppServiceProvider.
 *
 * Every borrower is told about their own loan; for a group facility that means each member about
 * their member loan. Loans are read without the branch scope because this runs after the request
 * (or in the nightly run), and the event itself was already authorised.
 */
class CustomerNotifier
{
    public function __construct(
        protected NotificationService $notifications,
    ) {}

    public function handleStatusChange(LoanStatusChanged $event): void
    {
        $notification = match ($event->to) {
            LoanStatus::Approved => NotificationEvent::LoanApproved,
            LoanStatus::Rejected => NotificationEvent::LoanRejected,
            LoanStatus::Active => NotificationEvent::LoanDisbursed,
            LoanStatus::Settled => NotificationEvent::LoanSettled,
            default => null,
        };

        if (! $notification) {
            return;
        }

        $loan = Loan::withoutGlobalScope('branch')->find($event->loan->id);
        $loans = $loan->isGroupFacility()
            ? $loan->memberLoans()->withoutGlobalScope('branch')->where('status', $event->to->value)->get()
            : collect([$loan]);

        foreach ($loans as $each) {
            foreach ($this->borrowers($each) as $party) {
                $this->notifications->notifyCustomer($notification, $party->customer, $this->loanContext($each), $each);
            }
        }
    }

    public function handlePayment(PaymentPosted $event): void
    {
        $txn = $event->transaction;

        if (! in_array($txn->type, [TransactionType::Repayment, TransactionType::EarlySettlement], true)) {
            return;
        }

        $loan = Loan::withoutGlobalScope('branch')->find($txn->loan_id);

        foreach ($this->borrowers($loan) as $party) {
            $this->notifications->notifyCustomer(NotificationEvent::PaymentReceived, $party->customer, $this->loanContext($loan) + [
                'amount' => (string) $txn->amount,
                'txn_no' => $txn->txn_no,
                'outstanding' => (string) Money::sum([$loan->outstanding_principal, $loan->outstanding_profit, $loan->outstanding_charges, $loan->outstanding_penalty]),
            ], $loan);
        }
    }

    /**
     * 90 DPD: the borrowers and every guarantor receive the recovery notice (D-3).
     */
    public function handleRecoveryOpened(RecoveryCaseOpened $event): void
    {
        $loan = Loan::withoutGlobalScope('branch')->with('branch')->find($event->case->loan_id);
        $context = $this->loanContext($loan) + [
            'arrears' => (string) $loan->arrears_amount,
            'days_past_due' => $loan->days_past_due,
            'branch_name' => $loan->branch->name,
        ];

        $borrowers = $this->borrowers($loan);

        foreach ($borrowers as $party) {
            $this->notifications->notifyCustomer(NotificationEvent::RecoveryNotice, $party->customer, $context, $loan);
        }

        $guarantors = LoanParty::with('customer')
            ->whereIn('loan_id', array_filter([$loan->id, $loan->parent_id]))
            ->where('role', PartyRole::Guarantor->value)
            ->whereNull('released_at')
            ->get();

        foreach ($guarantors as $guarantor) {
            $this->notifications->notifyCustomer(NotificationEvent::RecoveryNoticeGuarantor, $guarantor->customer, $context + [
                'borrower_name' => $borrowers->first()?->customer->name_with_initials,
            ], $loan);
        }
    }

    /**
     * @return Collection<int, LoanParty>
     */
    protected function borrowers(Loan $loan): Collection
    {
        return LoanParty::with('customer')->where('loan_id', $loan->id)->whereIn('role', PartyRole::borrowingValues())->get();
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function loanContext(Loan $loan): array
    {
        return [
            'application_no' => $loan->application_no,
            'loan_no' => $loan->loan_no ?? $loan->application_no,
            'amount' => (string) ($loan->approved_amount ?? $loan->requested_amount),
            'net_amount' => (string) $loan->net_disbursement_amount,
            'installment_amount' => (string) $loan->installment_amount,
            'tenure' => $loan->tenure,
            'first_due_date' => $loan->first_due_date?->toDateString(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\LoanStatus;
use App\Enums\PartyRole;
use App\Enums\RepaymentFrequency;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\LoanParty;
use App\Models\Setting;
use App\Support\BusinessDate;

/**
 * Can this person borrow, or guarantee? Each check returns a problem with a stable code, so the
 * same service answers the eligibility screen (all problems at once) and blocks an action
 * (first problem thrown).
 *
 * Rules: L-1 one open loan per person, L-2 valid KYC, borrower age limits, G-2 guarantee limit,
 * G-3 a guarantor who paid for a borrower is blocked until that loan is settled.
 */
class EligibilityService
{
    /**
     * @param  list<int>  $ignoreLoanIds  The application being checked (and its member loans), which must not block itself.
     * @return list<array{code: string, message: string}>
     */
    public function borrowerProblems(Customer $customer, int $tenure, RepaymentFrequency $frequency, bool $requireKyc, array $ignoreLoanIds = []): array
    {
        $problems = [];

        if (! $customer->is_active) {
            $problems[] = $this->problem('CUSTOMER_INACTIVE', 'The customer is inactive.');
        }

        if ($requireKyc && ! $customer->hasValidKyc()) {
            $problems[] = $this->problem('KYC_NOT_VERIFIED', 'The customer has not confirmed their details (KYC), or the confirmation has expired.');
        }

        $minAge = (int) Setting::value('borrower.min_age', 18);
        $maxAgeAtMaturity = (int) Setting::value('borrower.max_age_at_maturity', 65);

        if ($customer->age() < $minAge) {
            $problems[] = $this->problem('BORROWER_UNDER_AGE', "A borrower must be at least {$minAge} years old.");
        }

        if ($this->ageAtMaturity($customer, $tenure, $frequency) > $maxAgeAtMaturity) {
            $problems[] = $this->problem('BORROWER_TOO_OLD_AT_MATURITY', "The borrower would be older than {$maxAgeAtMaturity} when the loan ends. Choose a shorter tenure.");
        }

        if ($this->hasOpenLoan($customer, $ignoreLoanIds)) {
            $problems[] = $this->problem('BORROWER_HAS_OPEN_LOAN', 'The customer already has an open loan or application.');
        }

        if ($this->isBlockedGuarantor($customer)) {
            $problems[] = $this->problem('GUARANTOR_INVOKED_BLOCKED', 'The customer paid for a borrower they guaranteed and cannot borrow until that loan is settled.');
        }

        return $problems;
    }

    /**
     * Checks when a guarantor is added. KYC and documents are checked later, at verification,
     * so the guarantor can be recorded first and complete them afterwards.
     *
     * @return list<array{code: string, message: string}>
     */
    public function guarantorProblems(Customer $customer, int $ignoreLoanId): array
    {
        $problems = [];

        if (! $customer->is_active) {
            $problems[] = $this->problem('CUSTOMER_INACTIVE', 'The customer is inactive.');
        }

        $max = (int) Setting::value('guarantor.max_active_guarantees', 2);

        if ($this->openGuarantees($customer, $ignoreLoanId) >= $max) {
            $problems[] = $this->problem('GUARANTOR_LIMIT_REACHED', "This person already guarantees {$max} open loans, the maximum allowed.");
        }

        if ($this->isBlockedGuarantor($customer)) {
            $problems[] = $this->problem('GUARANTOR_INVOKED_BLOCKED', 'This person paid for a borrower they guaranteed and cannot give a new guarantee until that loan is settled.');
        }

        return $problems;
    }

    /**
     * Throw the first problem, with the full list in the details.
     *
     * @param  list<array{code: string, message: string}>  $problems
     */
    public function assertNone(array $problems, Customer $customer): void
    {
        if ($problems === []) {
            return;
        }

        throw new BusinessRuleException($problems[0]['code'], "{$customer->full_name} ({$customer->customer_no}): {$problems[0]['message']}", 409, [
            'customer_no' => $customer->customer_no,
            'problems' => $problems,
        ]);
    }

    /**
     * L-1. Borrowing parties hold open_loan_key while their loan is open (see LoanWorkflowService).
     * Drafts never hold a key, so a forgotten draft does not block anyone.
     *
     * @param  list<int>  $ignoreLoanIds
     */
    public function hasOpenLoan(Customer $customer, array $ignoreLoanIds = []): bool
    {
        return LoanParty::query()
            ->where('open_loan_key', $customer->id)
            ->when($ignoreLoanIds, fn ($q) => $q->whereNotIn('loan_id', $ignoreLoanIds))
            ->exists();
    }

    /**
     * G-2. Guarantees count from the moment they are recorded until the loan closes.
     */
    public function openGuarantees(Customer $customer, ?int $ignoreLoanId = null): int
    {
        return LoanParty::query()
            ->where('customer_id', $customer->id)
            ->where('role', PartyRole::Guarantor->value)
            ->whereNull('released_at')
            ->when($ignoreLoanId, fn ($q, $id) => $q->where('loan_id', '!=', $id))
            ->whereHas('loan', fn ($q) => $q->withoutGlobalScope('branch')->whereNotIn('status', LoanStatus::closedValues()))
            ->count();
    }

    /**
     * G-3 (D-2). Blocked until every loan this person paid for as guarantor is settled. A written-off
     * loan was never settled, so it keeps blocking.
     */
    public function isBlockedGuarantor(Customer $customer): bool
    {
        if (! Setting::value('guarantor.block_after_invocation', true)) {
            return false;
        }

        return LoanParty::query()
            ->where('customer_id', $customer->id)
            ->where('role', PartyRole::Guarantor->value)
            ->whereNotNull('invoked_at')
            ->whereHas('loan', fn ($q) => $q->withoutGlobalScope('branch')->where('status', '!=', LoanStatus::Settled->value))
            ->exists();
    }

    /**
     * Age on the expected last due date if the loan were disbursed today.
     */
    protected function ageAtMaturity(Customer $customer, int $tenure, RepaymentFrequency $frequency): int
    {
        $today = BusinessDate::todayImmutable();

        $maturity = match ($frequency) {
            RepaymentFrequency::Monthly, RepaymentFrequency::Bullet => $today->addMonthsNoOverflow($tenure),
            RepaymentFrequency::Fortnightly => $today->addWeeks(2 * $tenure),
            RepaymentFrequency::Weekly => $today->addWeeks($tenure),
        };

        return (int) $customer->date_of_birth->diffInYears($maturity);
    }

    /**
     * @return array{code: string, message: string}
     */
    protected function problem(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}

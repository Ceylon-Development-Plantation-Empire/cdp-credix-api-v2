<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\LoanStatus;
use App\Enums\PartyRole;
use App\Enums\RepaymentFrequency;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\Loan;
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
    public function borrowerProblems(Customer $customer, int $tenure, RepaymentFrequency $frequency, bool $requireKyc, array $ignoreLoanIds = [], bool $allowMultipleOpen = false): array
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

        // A loan given as a special case (Loan Multiple Open Allow) is not stopped by the rule.
        if (! $allowMultipleOpen && ($open = $this->openLoanOf($customer, $ignoreLoanIds))) {
            $problems[] = $this->problem(
                'BORROWER_HAS_OPEN_LOAN',
                "The customer already has an open loan or application: {$open->application_no} ({$open->status->value}). Continue that one instead of starting another.",
                ['loan' => ['public_id' => $open->public_id, 'application_no' => $open->application_no, 'loan_no' => $open->loan_no, 'status' => $open->status->value]],
            );
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
     * L-1. The customer's open loan, if any, found from the first step so the officer is told before filling anything in:
     * - a loan that holds the customer's open_loan_key (submitted or later, a written-off debt included: the database
     *   refuses a second one even when two applications are submitted at the same moment);
     * - a loan given as a special case (no key, so it is looked for here);
     * - while the setting loan.open_loan_scope is "draft" (the default), any application still in progress, drafts too.
     *
     * @param  list<int>  $ignoreLoanIds
     */
    public function openLoanOf(Customer $customer, array $ignoreLoanIds = []): ?Loan
    {
        $keyed = LoanParty::query()
            ->where('open_loan_key', $customer->id)
            ->when($ignoreLoanIds, fn ($q) => $q->whereNotIn('loan_id', $ignoreLoanIds))
            ->value('loan_id');

        if ($keyed !== null) {
            return Loan::withoutGlobalScope('branch')->find($keyed);
        }

        $draftsCount = Setting::value('loan.open_loan_scope', 'draft') === 'draft';

        return Loan::withoutGlobalScope('branch')
            ->open()
            ->when($ignoreLoanIds, fn ($q) => $q->whereNotIn('id', $ignoreLoanIds))
            ->whereHas('parties', fn ($p) => $p->where('customer_id', $customer->id)->whereIn('role', PartyRole::borrowingValues())->whereNull('released_at'))
            ->when(! $draftsCount, fn ($q) => $q->whereNotNull('multiple_open_at'))
            ->orderBy('id')
            ->first();
    }

    /**
     * The loan in a few fields, for screens that tell the officer "this customer already has one".
     *
     * @return array<string, mixed>|null
     */
    public function summary(?Loan $loan): ?array
    {
        return $loan === null ? null : [
            'public_id' => $loan->public_id,
            'application_no' => $loan->application_no,
            'loan_no' => $loan->loan_no,
            'status' => $loan->status->value,
        ];
    }

    /**
     * @param  list<int>  $ignoreLoanIds
     */
    public function hasOpenLoan(Customer $customer, array $ignoreLoanIds = []): bool
    {
        return $this->openLoanOf($customer, $ignoreLoanIds) !== null;
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
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function problem(string $code, string $message, array $extra = []): array
    {
        return ['code' => $code, 'message' => $message] + $extra;
    }
}

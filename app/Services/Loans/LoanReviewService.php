<?php

namespace App\Services\Loans;

use App\Enums\CollateralStatus;
use App\Enums\LoanStatus;
use App\Enums\RepaymentFrequency;
use App\Exceptions\BusinessRuleException;
use App\Finance\Support\Money;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanParty;
use App\Models\User;
use App\Traits\ActivityLogTrait;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Review → verify → approve, plus return, reject and cancel.
 *
 * Maker-checker (rule L-5): the person who submitted cannot review; the reviewer cannot verify;
 * nobody who submitted, reviewed or verified can approve; an approver approves only up to the
 * highest approval limit among their roles. There is no role-name exemption.
 *
 * Verification and approval run the same readiness checks and report every problem at once,
 * so the officer can fix them in one go.
 */
class LoanReviewService
{
    use ActivityLogTrait;

    public function __construct(
        protected LoanWorkflowService $workflow,
        protected LoanApplicationService $applications,
        protected LoanPricingService $pricing,
        protected EligibilityService $eligibility,
        protected AffordabilityService $affordability,
        protected LoanPartyService $parties,
        protected LoanChecklistService $checklist,
        protected CollateralService $collaterals,
    ) {}

    public function startReview(Loan $loan, User $by): Loan
    {
        return DB::transaction(function () use ($loan, $by) {
            $loan = $this->workflow->lock($loan);
            $this->assertMove($loan, LoanStatus::InReview);
            $this->workflow->assertSegregation($loan, $by, ['submitted' => LoanStatus::Submitted]);

            return $this->workflow->transition($loan, LoanStatus::InReview, $by);
        });
    }

    public function returnForCorrection(Loan $loan, string $remarks, User $by): Loan
    {
        return DB::transaction(function () use ($loan, $remarks, $by) {
            $loan = $this->workflow->lock($loan);
            $this->assertMove($loan, LoanStatus::Returned);

            return $this->workflow->transition($loan, LoanStatus::Returned, $by, $remarks);
        });
    }

    public function verify(Loan $loan, ?string $remarks, User $by): Loan
    {
        return DB::transaction(function () use ($loan, $remarks, $by) {
            $loan = $this->workflow->lock($loan);
            $this->assertMove($loan, LoanStatus::Verified);
            $this->workflow->assertSegregation($loan, $by, ['reviewed' => LoanStatus::InReview]);

            $borrowingLoans = $this->applications->borrowingLoans($loan);
            $amounts = $borrowingLoans->mapWithKeys(fn (Loan $each) => [$each->id => (string) $each->requested_amount])->all();

            // The assessment (DSR on verified income) is written on these same loan objects and saved.
            $this->assertReady($loan, $borrowingLoans, $amounts, (string) $loan->rate);

            foreach ($borrowingLoans as $each) {
                $each->save();
            }

            return $this->workflow->transition($loan, LoanStatus::Verified, $by, $remarks);
        });
    }

    /**
     * @param  array{approved_amount?: string|null, rate?: string|null, members?: list<array{customer_public_id: string, amount: string}>, remarks?: string|null}  $data
     */
    public function approve(Loan $loan, array $data, User $by): Loan
    {
        return DB::transaction(function () use ($loan, $data, $by) {
            $loan = $this->workflow->lock($loan);
            $this->assertMove($loan, LoanStatus::Approved);
            $this->workflow->assertSegregation($loan, $by, [
                'submitted' => LoanStatus::Submitted,
                'reviewed' => LoanStatus::InReview,
                'verified' => LoanStatus::Verified,
            ]);

            $terms = $this->pricing->termsOf($loan);
            $rate = BigDecimal::of($data['rate'] ?? $loan->rate);

            if ($rate->isLessThan($terms['min_rate']) || $rate->isGreaterThan($terms['max_rate'])) {
                throw new BusinessRuleException('RATE_OUT_OF_PRODUCT_LIMITS', "The rate must be between {$terms['min_rate']} and {$terms['max_rate']}% p.a.", 422);
            }

            $borrowingLoans = $this->applications->borrowingLoans($loan);
            $amounts = $this->approvedAmounts($loan, $borrowingLoans->all(), $data, $terms);
            $total = Money::sum($amounts);

            $this->assertApprovalAuthority($by, $total);
            $this->assertReady($loan, $borrowingLoans, $amounts, (string) $rate);

            foreach ($borrowingLoans as $each) {
                $pricing = $this->pricing->price($each, $amounts[$each->id], $rate);
                $each->forceFill(['approved_amount' => $amounts[$each->id], 'rate' => (string) $rate] + $pricing->columns())->save();
            }

            if ($loan->isGroupFacility()) {
                $loan->rate = (string) $rate;
                $this->applications->sumMemberColumns($loan, $borrowingLoans);
                $loan->save();
            }

            $this->logActivity('APPROVE', 'Loan', "Approved {$loan->application_no} for {$total}");

            return $this->workflow->transition($loan, LoanStatus::Approved, $by, $data['remarks'] ?? null, [
                'approved_amount' => (string) $total,
                'rate' => (string) $rate,
            ]);
        });
    }

    public function reject(Loan $loan, string $remarks, User $by): Loan
    {
        return DB::transaction(function () use ($loan, $remarks, $by) {
            $loan = $this->workflow->lock($loan);
            $this->assertMove($loan, LoanStatus::Rejected);

            return $this->workflow->transition($loan, LoanStatus::Rejected, $by, $remarks);
        });
    }

    public function cancel(Loan $loan, string $remarks, User $by): Loan
    {
        return DB::transaction(function () use ($loan, $remarks, $by) {
            $loan = $this->workflow->lock($loan);
            $this->assertMove($loan, LoanStatus::Cancelled);

            return $this->workflow->transition($loan, LoanStatus::Cancelled, $by, $remarks);
        });
    }

    /**
     * Every problem that stops the loan from being verified or approved for these amounts.
     *
     * @param  Collection<int, Loan>  $borrowingLoans  The loans carrying borrowers; their assessment columns are filled in.
     * @param  array<int, string>  $amounts  borrowing loan id => amount
     * @return list<array{code: string, message: string, customer_no?: string}>
     */
    public function problems(Loan $loan, Collection $borrowingLoans, array $amounts, string $rate): array
    {
        $terms = $this->pricing->termsOf($loan);
        $frequency = RepaymentFrequency::from($terms['repayment_frequency']);
        $loanIds = $loan->idsWithMemberLoans();
        $problems = [];

        if ($loan->isGroupFacility()) {
            try {
                $this->applications->assertGroupReady($loan, $terms, $borrowingLoans);
            } catch (BusinessRuleException $e) {
                $problems[] = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            }
        }

        $parties = LoanParty::with(['customer', 'loan'])->whereIn('loan_id', $loanIds)->whereNull('released_at')->get();

        foreach ($parties as $party) {
            $customer = $party->customer;

            // L-1, L-2 (borrowers) and G-1, G-3, G-5 (guarantors).
            $personal = $party->role->isBorrowing()
                ? $this->eligibility->borrowerProblems($customer, $loan->tenure, $frequency, true, $loanIds)
                : $this->guarantorProblems($loan, $party);

            foreach ($personal as $problem) {
                $problems[] = $problem + ['customer_no' => $customer->customer_no];
            }
        }

        try {
            $this->applications->assertGuarantorCount($loan, $terms, atLeastMinimum: true);
        } catch (BusinessRuleException $e) {
            $problems[] = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        }

        // L-3: documents of every person and every collateral.
        $checklist = $this->checklist->forLoan($loan);

        foreach ($checklist['parties'] as $party) {
            if (! $party['complete']) {
                $problems[] = ['code' => 'DOCUMENTS_INCOMPLETE', 'message' => "Documents are incomplete for the {$party['role']}.", 'customer_no' => $party['customer']['customer_no']];
            }
        }

        array_push($problems, ...$this->collateralProblems($loan, $terms, Money::sum($amounts)));

        // Affordability, priced on the amounts being decided.
        foreach ($borrowingLoans as $each) {
            if (! isset($amounts[$each->id]) || ! $this->affordability->applies($each)) {
                continue;
            }

            $this->affordability->assess($each, $this->pricing->price($each, $amounts[$each->id], $rate), verifiedIncomeOnly: true);

            if (! $this->affordability->isAffordable($each, $terms)) {
                $max = (string) $this->affordability->maxPercent($terms);
                $problems[] = ['code' => 'DSR_TOO_HIGH', 'message' => $each->dsr_percent === null
                    ? 'No verified income is recorded, so affordability cannot be shown.'
                    : "The debt service ratio is {$each->dsr_percent}%, above the {$max}% allowed."];
            }
        }

        return $problems;
    }

    /**
     * @param  Collection<int, Loan>  $borrowingLoans
     * @param  array<int, string>  $amounts
     */
    protected function assertReady(Loan $loan, Collection $borrowingLoans, array $amounts, string $rate): void
    {
        $problems = $this->problems($loan, $borrowingLoans, $amounts, $rate);

        if ($problems) {
            throw new BusinessRuleException('LOAN_NOT_READY', 'The loan is not ready for this step. See the problems listed.', 422, ['problems' => $problems]);
        }
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    protected function guarantorProblems(Loan $loan, LoanParty $party): array
    {
        $problems = [];

        if (! $party->customer->hasValidKyc()) {
            $problems[] = ['code' => 'KYC_NOT_VERIFIED', 'message' => 'The guarantor has not confirmed their details (KYC).'];
        }

        if (! $this->parties->hasCurrentConsent($loan, $party)) {
            $problems[] = ['code' => 'GUARANTOR_CONSENT_MISSING', 'message' => 'The guarantor has not agreed (by OTP) to the application as it is now.'];
        }

        if ($this->eligibility->isBlockedGuarantor($party->customer)) {
            $problems[] = ['code' => 'GUARANTOR_INVOKED_BLOCKED', 'message' => 'The guarantor paid for another borrower and is blocked until that loan is settled.'];
        }

        return $problems;
    }

    /**
     * Every recorded collateral verified, every mandatory type present, and (when the product
     * requires collateral) the eligible amount covers the loan.
     *
     * @param  array<string, mixed>  $terms
     * @return list<array{code: string, message: string}>
     */
    protected function collateralProblems(Loan $loan, array $terms, BigDecimal $amount): array
    {
        $problems = [];
        $held = $loan->collaterals()->whereIn('status', [CollateralStatus::Proposed->value, CollateralStatus::Verified->value, CollateralStatus::Perfected->value])->get();

        if ($held->contains('status', CollateralStatus::Proposed)) {
            $problems[] = ['code' => 'COLLATERAL_NOT_VERIFIED', 'message' => 'Some collateral has not been verified yet.'];
        }

        foreach ($terms['collateral_rules'] as $rule) {
            if ($rule['is_mandatory'] && ! $held->contains(fn ($c) => $c->type->value === $rule['collateral_type'])) {
                $problems[] = ['code' => 'COLLATERAL_REQUIRED', 'message' => "A {$rule['collateral_type']} collateral is required for this product."];
            }
        }

        if ($terms['requires_collateral']) {
            $coverage = $this->collaterals->coverage($loan);

            if ($coverage->isLessThan($amount)) {
                $problems[] = ['code' => 'COLLATERAL_COVERAGE_SHORT', 'message' => "The verified collateral secures {$coverage}, less than the loan amount of {$amount}."];
            }
        }

        return $problems;
    }

    /**
     * Approved amount per borrowing loan: as requested unless the approver lowers it. It can never
     * be more than requested (the borrowers and guarantors did not ask for more).
     *
     * @param  list<Loan>  $borrowingLoans
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $terms
     * @return array<int, string>
     */
    protected function approvedAmounts(Loan $loan, array $borrowingLoans, array $data, array $terms): array
    {
        $overrides = [];

        if ($loan->isGroupFacility()) {
            foreach ($data['members'] ?? [] as $row) {
                $customerId = Customer::where('public_id', $row['customer_public_id'])->value('id');
                $overrides[$customerId] = $row['amount'];
            }
        } elseif (isset($data['approved_amount'])) {
            $overrides[$loan->customer_id] = $data['approved_amount'];
        }

        $amounts = [];

        foreach ($borrowingLoans as $each) {
            $amount = Money::of($overrides[$each->customer_id] ?? $each->requested_amount);

            if ($amount->isGreaterThan($each->requested_amount)) {
                throw new BusinessRuleException('APPROVED_ABOVE_REQUESTED', 'The approved amount cannot be more than the amount applied for.', 422);
            }

            $this->applications->assertAmount($terms, $amount);
            $amounts[$each->id] = (string) $amount;
        }

        return $amounts;
    }

    /**
     * The approval authority matrix: the highest max_approval_amount among the user's roles.
     * A role without a limit cannot approve loans.
     */
    protected function assertApprovalAuthority(User $user, BigDecimal $amount): void
    {
        $limit = $user->roles()->max('max_approval_amount');

        if ($limit === null) {
            throw new BusinessRuleException('APPROVAL_AUTHORITY_MISSING', 'Your role has no loan approval limit.', 403);
        }

        if ($amount->isGreaterThan($limit)) {
            throw new BusinessRuleException('APPROVAL_LIMIT_EXCEEDED', "This loan is above your approval limit of {$limit}. It needs a higher authority.", 403, [
                'limit' => (string) Money::of($limit),
            ]);
        }
    }

    protected function assertMove(Loan $loan, LoanStatus $to): void
    {
        if ($loan->isMemberLoan()) {
            throw new BusinessRuleException('MEMBER_LOAN_ACTION', 'This is a member loan of a group facility. Work on the group facility instead.', 422);
        }

        if (! $loan->status->canMoveTo($to)) {
            throw new BusinessRuleException('LOAN_INVALID_TRANSITION', "A loan that is {$loan->status->value} cannot be moved to {$to->value}.", 409);
        }
    }
}

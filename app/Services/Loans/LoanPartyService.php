<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Support\BusinessDate;
use App\Enums\ClosureReason;
use App\Enums\LoanActivityType;
use App\Enums\LoanStatus;
use App\Enums\PartyRole;
use App\Exceptions\BusinessRuleException;
use App\Models\BorrowerGroupMember;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanParty;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Guarantors (rules G-1 … G-6, GR-7), their OTP consent, and the borrowers' acceptance of the offer:
 * - a guarantor agrees to guarantee the application by OTP (before verification);
 * - every borrower accepts the approved offer (before disbursement), recorded by the officer without a code.
 *
 * Each confirmation stores a hash of the terms that were confirmed. If those terms change
 * afterwards the confirmation no longer matches and has to be given again.
 */
class LoanPartyService
{
    /**
     * Guarantors can be added, removed and give consent until the application is verified.
     */
    protected const GUARANTOR_WINDOW = [LoanStatus::Draft, LoanStatus::Submitted, LoanStatus::InReview, LoanStatus::Returned];

    public function __construct(
        protected LoanWorkflowService $workflow,
        protected LoanPricingService $pricing,
        protected EligibilityService $eligibility,
    ) {}

    public function addGuarantor(Loan $loan, Customer $customer, ?string $relationship, User $by): LoanParty
    {
        return DB::transaction(function () use ($loan, $customer, $relationship, $by) {
            $loan = $this->workflow->lock($loan);
            $this->assertGuarantorWindow($loan);

            $terms = $this->pricing->termsOf($loan);

            if ((int) $terms['max_guarantors'] === 0) {
                throw new BusinessRuleException('GUARANTOR_NOT_ALLOWED', 'This product does not take guarantors.', 422);
            }

            if ($loan->guarantors()->count() >= $terms['max_guarantors']) {
                throw new BusinessRuleException('GUARANTOR_COUNT_EXCEEDED', "This product allows at most {$terms['max_guarantors']} guarantor(s).", 422);
            }

            // Lock the person so two loans adding the same guarantor at once count each other (G-2).
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();

            // G-4: nobody is borrower and guarantor on the same loan (or the same group facility).
            if (LoanParty::whereIn('loan_id', $loan->idsWithMemberLoans())->where('customer_id', $customer->id)->exists()) {
                throw new BusinessRuleException('GUARANTOR_IS_PARTY', 'This person is already on this loan and cannot also guarantee it.', 409);
            }

            // GR-7 (D-8): group members do not guarantee each other.
            if ($loan->isGroupFacility() && BorrowerGroupMember::where('borrower_group_id', $loan->borrower_group_id)->where('customer_id', $customer->id)->whereNull('left_on')->exists()) {
                throw new BusinessRuleException('GROUP_MEMBER_CANNOT_GUARANTEE', 'A member of the group cannot guarantee the group\'s loan.', 422);
            }

            $this->eligibility->assertNone($this->eligibility->guarantorProblems($customer, $loan->id), $customer);

            try {
                $party = $loan->parties()->create([
                    'customer_id' => $customer->id,
                    'role' => PartyRole::Guarantor,
                    'relationship' => $relationship,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new BusinessRuleException('GUARANTOR_IS_PARTY', 'This person is already on this loan.', 409);
            }

            $this->workflow->record($loan, LoanActivityType::Update, $by, "Guarantor {$customer->customer_no} added");

            return $party;
        });
    }

    public function removeGuarantor(Loan $loan, LoanParty $party, User $by): void
    {
        DB::transaction(function () use ($loan, $party, $by) {
            $loan = $this->workflow->lock($loan);
            $this->assertGuarantorWindow($loan);

            if ($party->role !== PartyRole::Guarantor || $party->loan_id !== $loan->id) {
                throw new BusinessRuleException('NOT_A_GUARANTOR', 'This party is not a guarantor of this loan.', 422);
            }

            $party->delete();

            $this->workflow->record($loan, LoanActivityType::Update, $by, "Guarantor {$party->customer->customer_no} removed");
        });
    }

    /* Offer acceptance */

    /**
     * The borrower agreed to the approved offer and the officer records it. No code is sent for this:
     * the customer's own OTP confirmation is the review link (LoanReviewLinkService), once per person;
     * here the officer only notes how the customer agreed (a visit, a call, a signed copy).
     *
     * Once every borrower of the loan (every member, for a group) has accepted, the loan moves to
     * accepted. The terms are hashed, so changing them afterwards cancels the acceptance (rule L-7).
     */
    public function acceptOffer(Loan $loan, LoanParty $party, ?string $remarks, User $by): Loan
    {
        $this->assertOfferOpen($loan);
        $this->assertRole($party, guarantor: false);

        $hash = $this->hash($this->offerTerms($party->loan));
        $how = $remarks !== null && trim($remarks) !== '' ? ': '.trim($remarks) : '';

        return DB::transaction(function () use ($loan, $party, $hash, $by, $how) {
            $loan = Loan::withoutGlobalScope('branch')->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $this->assertOfferOpen($loan);

            $party->forceFill(['consented_at' => now(), 'consent_hash' => $hash])->save();
            $loan->forceFill(['offer_on_hold_at' => null, 'offer_remarks' => null])->save();
            $this->workflow->record($loan, LoanActivityType::Update, $by, "Offer accepted by {$party->customer->customer_no}{$how}");

            $waiting = LoanParty::whereIn('loan_id', $loan->idsWithMemberLoans())
                ->whereIn('role', PartyRole::borrowingValues())
                ->whereNull('released_at')
                ->whereNull('consented_at')
                ->exists();

            return $waiting ? $loan : $this->workflow->transition($loan, LoanStatus::Accepted, $by, 'All borrowers accepted the offer');
        });
    }

    /**
     * The customer asked to wait. The loan stays approved (the offer is still valid until it expires)
     * and shows as on hold with the reason; saying it again replaces the note, and every call is on the timeline.
     */
    public function holdOffer(Loan $loan, string $remarks, User $by): Loan
    {
        return DB::transaction(function () use ($loan, $remarks, $by) {
            $loan = $this->workflow->lock($loan);
            $this->assertOfferOpen($loan);

            $loan->forceFill(['offer_on_hold_at' => now(), 'offer_remarks' => $remarks])->save();
            $this->workflow->record($loan, LoanActivityType::Update, $by, "Offer put on hold by the customer: {$remarks}");

            return $loan;
        });
    }

    public function declineOffer(Loan $loan, string $remarks, User $by): Loan
    {
        return DB::transaction(function () use ($loan, $remarks, $by) {
            $loan = $this->workflow->lock($loan);

            if ($loan->status !== LoanStatus::Approved) {
                throw new BusinessRuleException('OFFER_NOT_OPEN', 'There is no open offer on this loan.', 409);
            }

            $loan->forceFill(['offer_on_hold_at' => null, 'offer_remarks' => $remarks])->save();

            return $this->workflow->transition($loan, LoanStatus::Cancelled, $by, "Customer declined the offer: {$remarks}", [], ClosureReason::Declined);
        });
    }

    /**
     * True while the borrower's acceptance matches the terms of the loan (rule L-7).
     */
    public function hasCurrentAcceptance(LoanParty $party): bool
    {
        return $party->consented_at !== null && hash_equals((string) $party->consent_hash, $this->hash($this->offerTerms($party->loan)));
    }

    /**
     * What a guarantor agrees to: whose loan, how much, how long.
     *
     * @return array<string, mixed>
     */
    public function guaranteeTerms(Loan $loan): array
    {
        $borrowers = LoanParty::with('customer')->whereIn('loan_id', $loan->idsWithMemberLoans())
            ->whereIn('role', PartyRole::borrowingValues())->whereNull('released_at')->get()
            ->map(fn (LoanParty $p) => $p->customer->customer_no)->sort()->values()->all();

        return [
            'application_no' => $loan->application_no,
            'product' => $loan->product->slug,
            'borrowers' => $borrowers,
            'amount' => (string) $loan->requested_amount,
            'tenure' => $loan->tenure,
            'repayment_frequency' => $loan->repayment_frequency->value,
        ];
    }

    /**
     * What a borrower accepts: the approved terms of their own loan.
     *
     * @return array<string, mixed>
     */
    public function offerTerms(Loan $loan): array
    {
        return [
            'application_no' => $loan->application_no,
            'approved_amount' => (string) $loan->approved_amount,
            'rate' => (string) $loan->rate,
            'tenure' => $loan->tenure,
            'repayment_frequency' => $loan->repayment_frequency->value,
            'installment_amount' => (string) $loan->installment_amount,
            'profit_amount' => (string) $loan->profit_amount,
            'upfront_charges' => (string) $loan->upfront_charges,
            'net_disbursement_amount' => (string) $loan->net_disbursement_amount,
        ];
    }

    /**
     * A party of this loan, or of one of its member loans when it is a group facility.
     */
    public function findParty(Loan $loan, int $partyId): ?LoanParty
    {
        return LoanParty::with(['customer', 'loan'])->whereKey($partyId)->whereIn('loan_id', $loan->idsWithMemberLoans())->first();
    }

    protected function assertGuarantorWindow(Loan $loan): void
    {
        if ($loan->isMemberLoan()) {
            throw new BusinessRuleException('MEMBER_LOAN_ACTION', 'Guarantors of a group loan are added on the group facility.', 422);
        }

        if (! in_array($loan->status, self::GUARANTOR_WINDOW, true)) {
            throw new BusinessRuleException('GUARANTORS_LOCKED', 'Guarantors can no longer change: the application has been verified.', 409);
        }
    }

    protected function assertOfferOpen(Loan $loan): void
    {
        if ($loan->status !== LoanStatus::Approved) {
            throw new BusinessRuleException('OFFER_NOT_OPEN', 'There is no open offer on this loan.', 409);
        }

        $days = (int) Setting::value('loan.offer_validity_days', 30);

        if (BusinessDate::today()->greaterThan(BusinessDate::of($loan->approved_at)->addDays($days))) {
            throw new BusinessRuleException('OFFER_EXPIRED', "The offer was valid for {$days} days and has expired. Cancel the loan; a new application is needed.", 409);
        }
    }

    protected function assertRole(LoanParty $party, bool $guarantor): void
    {
        if ($party->released_at !== null) {
            throw new BusinessRuleException('PARTY_RELEASED', 'This person is no longer on the loan.', 409);
        }

        if ($guarantor && $party->role !== PartyRole::Guarantor) {
            throw new BusinessRuleException('NOT_A_GUARANTOR', 'Only a guarantor gives guarantor consent.', 422);
        }

        if (! $guarantor && ! $party->role->isBorrowing()) {
            throw new BusinessRuleException('NOT_A_BORROWER', 'Only a borrower accepts the loan offer.', 422);
        }

        if (! $guarantor && $party->hasConsented()) {
            throw new BusinessRuleException('OFFER_ALREADY_ACCEPTED', 'This borrower has already accepted the offer.', 409);
        }
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    protected function hash(array $terms): string
    {
        return hash('sha256', json_encode($terms));
    }
}

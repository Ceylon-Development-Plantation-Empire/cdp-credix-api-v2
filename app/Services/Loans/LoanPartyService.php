<?php

namespace App\Services\Loans;

use App\Enums\ClosureReason;
use App\Enums\LoanActivityType;
use App\Enums\LoanStatus;
use App\Enums\OtpPurpose;
use App\Enums\PartyRole;
use App\Exceptions\BusinessRuleException;
use App\Models\BorrowerGroupMember;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanParty;
use App\Models\OtpChallenge;
use App\Models\Setting;
use App\Models\User;
use App\Services\Security\OtpService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Guarantors (rules G-1 … G-6, GR-7) and the two OTP confirmations made by parties:
 * - a guarantor agrees to guarantee the application (before verification);
 * - every borrower accepts the approved offer (before disbursement).
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
        protected OtpService $otp,
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

    /* Guarantor consent */

    public function sendGuarantorConsent(Loan $loan, LoanParty $party, User $by): OtpChallenge
    {
        $this->assertGuarantorWindow($loan);
        $this->assertRole($party, guarantor: true);

        $terms = $this->guaranteeTerms($loan);
        $customer = $party->customer;
        $borrowers = $loan->isGroupFacility() ? "group {$loan->borrowerGroup->group_no}" : $loan->customer->full_name;

        $message = "CDP Capital: :code is your code to agree to GUARANTEE loan {$loan->application_no} of Rs {$terms['amount']} for {$borrowers}. "
            .'As guarantor you must pay if the borrower does not. Valid :minutes minutes. Never share this code.';

        return $this->otp->issue(OtpPurpose::GuarantorConsent, $party, $customer->phone_primary, $message, $terms, $this->hash($terms), $by);
    }

    public function verifyGuarantorConsent(Loan $loan, LoanParty $party, string $code, User $by): LoanParty
    {
        $this->assertGuarantorWindow($loan);
        $this->assertRole($party, guarantor: true);

        $hash = $this->hash($this->guaranteeTerms($loan));

        // Outside a transaction on purpose: a wrong code must stay counted (OtpService::verify).
        $this->otp->verify($this->pendingChallenge($party, OtpPurpose::GuarantorConsent), $code, $hash);

        $party->forceFill(['consented_at' => now(), 'consent_hash' => $hash])->save();
        $this->workflow->record($loan, LoanActivityType::Update, $by, "Guarantor {$party->customer->customer_no} consented by OTP");

        return $party;
    }

    /**
     * True while the guarantor's consent matches the application as it is now.
     */
    public function hasCurrentConsent(Loan $loan, LoanParty $party): bool
    {
        return $party->consented_at !== null && hash_equals((string) $party->consent_hash, $this->hash($this->guaranteeTerms($loan)));
    }

    /* Offer acceptance */

    public function sendOffer(Loan $loan, LoanParty $party, User $by): OtpChallenge
    {
        $this->assertOfferOpen($loan);
        $this->assertRole($party, guarantor: false);

        $terms = $this->offerTerms($party->loan);

        $message = "CDP Capital: :code is your code to ACCEPT loan {$loan->application_no}: Rs {$terms['approved_amount']}, "
            ."{$terms['tenure']} installments of Rs {$terms['installment_amount']}. Valid :minutes minutes. Never share this code.";

        return $this->otp->issue(OtpPurpose::LoanAcceptance, $party, $party->customer->phone_primary, $message, $terms, $this->hash($terms), $by);
    }

    /**
     * The borrower reads back the code. Once every borrower of the loan (every member, for a
     * group) has accepted, the loan moves to accepted.
     */
    public function acceptOffer(Loan $loan, LoanParty $party, string $code, User $by): Loan
    {
        return $this->accept($loan, $party, $this->pendingChallenge($party, OtpPurpose::LoanAcceptance), $code, $by, 'at the branch');
    }

    protected function accept(Loan $loan, LoanParty $party, OtpChallenge $challenge, string $code, ?User $by, string $how): Loan
    {
        $this->assertOfferOpen($loan);
        $this->assertRole($party, guarantor: false);

        $hash = $this->hash($this->offerTerms($party->loan));

        // Outside a transaction on purpose: a wrong code must stay counted (OtpService::verify).
        $this->otp->verify($challenge, $code, $hash);

        return DB::transaction(function () use ($loan, $party, $hash, $by, $how) {
            $loan = Loan::withoutGlobalScope('branch')->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $this->assertOfferOpen($loan);

            $party->forceFill(['consented_at' => now(), 'consent_hash' => $hash])->save();
            $this->workflow->record($loan, LoanActivityType::Update, $by, "Offer accepted by {$party->customer->customer_no} (OTP {$how})");

            $waiting = LoanParty::whereIn('loan_id', $loan->idsWithMemberLoans())
                ->whereIn('role', PartyRole::borrowingValues())
                ->whereNull('released_at')
                ->whereNull('consented_at')
                ->exists();

            return $waiting ? $loan : $this->workflow->transition($loan, LoanStatus::Accepted, $by, 'All borrowers accepted the offer');
        });
    }

    public function declineOffer(Loan $loan, ?string $remarks, User $by): Loan
    {
        return DB::transaction(function () use ($loan, $remarks, $by) {
            $loan = $this->workflow->lock($loan);

            if ($loan->status !== LoanStatus::Approved) {
                throw new BusinessRuleException('OFFER_NOT_OPEN', 'There is no open offer on this loan.', 409);
            }

            return $this->workflow->transition($loan, LoanStatus::Cancelled, $by, $remarks ?? 'Customer declined the offer', [], ClosureReason::Declined);
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

        if ($loan->approved_at->copy()->addDays($days)->isPast()) {
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

    protected function pendingChallenge(LoanParty $party, OtpPurpose $purpose): OtpChallenge
    {
        $challenge = $this->otp->latestPending($party, $purpose);

        if (! $challenge) {
            throw new BusinessRuleException('OTP_NOT_REQUESTED', 'No code is waiting for this person. Send a new code first.', 422);
        }

        return $challenge;
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    protected function hash(array $terms): string
    {
        return hash('sha256', json_encode($terms));
    }
}

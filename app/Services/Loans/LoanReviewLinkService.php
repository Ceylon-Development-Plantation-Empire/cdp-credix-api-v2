<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\LoanActivityType;
use App\Enums\LoanStatus;
use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use App\Enums\PartyRole;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanActivity;
use App\Models\LoanParty;
use App\Models\OtpChallenge;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Security\OtpService;
use App\Support\Mask;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The customer review link: one SMS link per borrower where they read their details and the loan
 * terms, ask for corrections and confirm once with an SMS code.
 *
 * - The link holds a random token; only its SHA-256 is stored (loan_parties.review_token_hash).
 * - What the customer confirms is hashed (their KYC details and the loan terms). If either changes
 *   afterwards the confirmation is "stale" and the customer has to confirm again.
 * - One code per person: the same code flow serves the link and the officer-assisted confirmation
 *   (a customer without a phone for the link reads the SMS code to the officer).
 * - Corrections are loan timeline entries, written once; an officer's answer is a second entry.
 */
class LoanReviewLinkService
{
    /**
     * The link can be sent from submission until the loan is paid out.
     */
    protected const OPEN_STATUSES = [LoanStatus::Submitted, LoanStatus::InReview, LoanStatus::Verified, LoanStatus::Approved, LoanStatus::Accepted];

    /**
     * Open correction requests one person can have at the same time.
     */
    protected const MAX_OPEN_CORRECTIONS = 10;

    public const DECLARATION = 'I confirm that my personal details, the loan terms, the repayment schedule and the guarantors and securities shown here are correct, and that I accept the terms of this loan.';

    public function __construct(
        protected OtpService $otp,
        protected NotificationService $notifications,
        protected LoanWorkflowService $workflow,
    ) {}

    /* Staff: status, sending, assisted confirmation, corrections */

    /**
     * Who has to confirm and where each person stands.
     *
     * @return array<string, mixed>
     */
    public function status(Loan $loan): array
    {
        $parties = $this->recipients($loan);
        $corrections = $this->corrections($loan);

        $customers = $parties->map(function (LoanParty $party) use ($corrections) {
            $own = $corrections->where('customer.public_id', $party->customer->public_id);
            $pending = $this->latestPending($party);

            return [
                'customer_id' => $party->customer->public_id,
                'customer_code' => $party->customer->customer_no,
                'full_name' => $party->customer->full_name,
                'status' => $this->statusOf($party),
                'customer_phone_masked' => PhoneNormalizer::mask($party->customer->phone_primary),
                'link_sent_at' => $party->review_sent_at?->toApiString(),
                'link_expires_at' => null,
                'opens_left' => max(0, $this->maxOpens() - $party->review_open_count),
                'viewed_at' => $party->review_viewed_at?->toApiString(),
                'confirmed_at' => $party->review_confirmed_at?->toApiString(),
                'confirmed_via' => $party->review_confirmed_via,
                'otp_expires_at' => $pending && ! $pending->isExpired() ? $pending->expires_at->toApiString() : null,
                'open_correction_requests' => $own->where('status', 'pending_review')->count(),
            ];
        })->values();

        return [
            'loan_public_id' => $loan->public_id,
            'loan_status' => $loan->status->value,
            'all_confirmed' => $customers->isNotEmpty() && $customers->every(fn ($c) => $c['status'] === 'confirmed'),
            'customers' => $customers->all(),
        ];
    }

    /**
     * Send a new link to every borrower who has not confirmed (or to the people named). A new link
     * replaces the old one and cancels any code waiting for the old one.
     *
     * @param  list<string>|null  $customerPublicIds
     * @return list<array<string, mixed>>
     */
    public function sendLinks(Loan $loan, ?array $customerPublicIds, User $by): array
    {
        $this->assertOpen($loan);

        $parties = $this->recipients($loan)
            ->when($customerPublicIds, fn (Collection $all) => $all->filter(fn (LoanParty $p) => in_array($p->customer->public_id, $customerPublicIds, true)));

        if ($parties->isEmpty()) {
            throw new BusinessRuleException('NO_REVIEW_RECIPIENTS', 'There is nobody to send the review link to on this loan.', 422);
        }

        return $parties->map(function (LoanParty $party) use ($by) {
            $customer = $party->customer;
            $base = ['customer_id' => $customer->public_id, 'full_name' => $customer->full_name, 'customer_phone_masked' => PhoneNormalizer::mask($customer->phone_primary)];

            if ($this->statusOf($party) === 'confirmed') {
                return $base + ['status' => 'confirmed', 'link_sent' => false, 'sms_sent' => false, 'confirmed_at' => $party->review_confirmed_at->toApiString()];
            }

            $token = Str::random(48);

            OtpChallenge::query()
                ->where('subject_type', $party->getMorphClass())->where('subject_id', $party->id)
                ->where('purpose', OtpPurpose::LoanReview->value)->where('status', OtpStatus::Pending->value)
                ->update(['status' => OtpStatus::Superseded->value]);

            $party->forceFill([
                'review_token_hash' => $this->hashToken($token),
                'review_sent_at' => now(),
                // The link is spent by opens, not by time: each load of the page counts (loan_review.max_opens).
                'review_expires_at' => null,
                'review_open_count' => 0,
                'review_viewed_at' => null,
                'review_confirmed_at' => null,
                'review_hash' => null,
                'review_confirmed_via' => null,
                'review_reason' => null,
            ])->save();

            $link = $this->linkFor($token);
            $log = $this->notifications->notifyCustomer(NotificationEvent::LoanReviewLink, $customer, [
                'application_no' => $party->loan->application_no,
                'review_link' => $link,
            ], $party->loan);

            $sent = in_array($log->status, [NotificationStatus::Queued, NotificationStatus::Sent], true);
            $this->workflow->record($this->rootOf($party), LoanActivityType::Update, $by, "Review link sent to {$customer->customer_no}".($sent ? '' : ' (the SMS could not be queued)'));

            return $base + [
                'status' => 'pending',
                'link_sent' => true,
                'sms_sent' => $sent,
                'opens_left' => $this->maxOpens(),
                // Only on a developer machine: the link otherwise exists only in the customer's SMS.
                'dev_link' => app()->environment('local', 'testing') ? $link : null,
            ];
        })->values()->all();
    }

    /**
     * What an officer sees before assisting: the declaration and the people.
     *
     * @return array<string, mixed>
     */
    public function preview(Loan $loan): array
    {
        $status = $this->status($loan);

        return [
            'loan_public_id' => $loan->public_id,
            'loan_status' => $loan->status->value,
            'all_confirmed' => $status['all_confirmed'],
            'declaration_text' => self::DECLARATION,
            'customers' => $status['customers'],
            'staff_assist_reason_min_length' => 10,
        ];
    }

    /**
     * Officer-assisted: send the code to the customer's phone; they read it out at the branch or on a call.
     */
    public function staffSendOtp(Loan $loan, string $customerPublicId, string $reason, User $by): OtpChallenge
    {
        $this->assertOpen($loan);
        $party = $this->partyOf($loan, $customerPublicId);
        $this->assertConfirmable($party);

        $challenge = $this->issueCode($party, $reason, $by);
        $this->workflow->record($this->rootOf($party), LoanActivityType::Update, $by, "Confirmation code sent to {$party->customer->customer_no} by an officer: {$reason}");

        return $challenge;
    }

    /**
     * Officer-assisted: the customer read the code out; the officer certifies they accepted the declaration.
     */
    public function staffVerifyOtp(Loan $loan, string $customerPublicId, string $code, User $by): LoanParty
    {
        $this->assertOpen($loan);
        $party = $this->partyOf($loan, $customerPublicId);
        $this->assertConfirmable($party);

        $challenge = $this->pendingCode($party);
        $reason = (string) ($challenge->payload['staff_assist_reason'] ?? 'Officer assisted');

        // Outside a transaction on purpose: a wrong code must stay counted (OtpService::verify).
        $this->otp->verify($challenge, $code, $this->hashFor($party));
        $this->markConfirmed($party, 'staff', $reason);

        $this->workflow->record($this->rootOf($party), LoanActivityType::Update, $by, "{$party->customer->customer_no} confirmed the loan details with an officer (code read out). Reason: {$reason}");

        return $party;
    }

    /**
     * Every correction request of the loan, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function corrections(Loan $loan): Collection
    {
        $activities = LoanActivity::query()
            ->where('loan_id', $loan->id)
            ->whereIn('type', [LoanActivityType::CorrectionRequest->value, LoanActivityType::CorrectionResolved->value])
            ->orderBy('id')
            ->get();

        $answers = $activities->where('type', LoanActivityType::CorrectionResolved)->keyBy(fn (LoanActivity $a) => $a->metadata['request_id'] ?? 0);

        $users = User::whereIn('id', $answers->pluck('performed_by')->filter()->unique())->pluck('name', 'id');

        return $activities->where('type', LoanActivityType::CorrectionRequest)->map(function (LoanActivity $request) use ($answers, $users) {
            $answer = $answers->get($request->id);
            $meta = $request->metadata ?? [];

            return [
                'id' => $request->id,
                'customer' => ['public_id' => $meta['customer_public_id'] ?? null, 'customer_no' => $meta['customer_no'] ?? null, 'full_name' => $meta['full_name'] ?? null],
                'stage' => $meta['stage'] ?? null,
                'field_name' => $meta['field_name'] ?? null,
                'current_value' => $meta['current_value'] ?? null,
                'proposed_value' => $meta['proposed_value'] ?? null,
                'reason' => $meta['reason'] ?? null,
                'status' => $answer ? (($answer->metadata['outcome'] ?? 'accepted') === 'accepted' ? 'accepted' : 'declined') : 'pending_review',
                'submitted_at' => $request->performed_at->toApiString(),
                'resolved_at' => $answer?->performed_at->toApiString(),
                'resolved_by' => $answer?->performed_by ? ($users[$answer->performed_by] ?? null) : null,
                'resolution_remarks' => $answer?->remarks,
            ];
        })->sortByDesc('id')->values();
    }

    /**
     * The officer answers a correction: accepted (they will change the record; the customer then
     * confirms again) or declined (the details stay as they are).
     */
    public function resolveCorrection(Loan $loan, int $requestId, string $outcome, string $remarks, User $by): array
    {
        $request = LoanActivity::where('loan_id', $loan->id)->where('type', LoanActivityType::CorrectionRequest->value)->whereKey($requestId)->first();

        if (! $request) {
            throw new BusinessRuleException('CORRECTION_NOT_FOUND', 'That correction request does not exist on this loan.', 404);
        }

        $already = $this->corrections($loan)->firstWhere('id', $requestId);

        if ($already && $already['status'] !== 'pending_review') {
            throw new BusinessRuleException('CORRECTION_ALREADY_ANSWERED', 'This correction request has already been answered.', 409);
        }

        $this->workflow->record($loan, LoanActivityType::CorrectionResolved, $by, $remarks, ['request_id' => $request->id, 'outcome' => $outcome]);

        // The officer corrected the record: once this customer has no other request waiting, they get a new link
        // to read the corrected details and confirm (the old link and any waiting code stop working).
        $customerPublicId = $request->metadata['customer_public_id'] ?? null;
        $newLink = false;

        if ($outcome === 'accepted' && $customerPublicId && in_array($loan->status, self::OPEN_STATUSES, true)
            && $this->corrections($loan)->where('customer.public_id', $customerPublicId)->where('status', 'pending_review')->isEmpty()) {
            $sent = collect($this->sendLinks($loan, [$customerPublicId], $by))->firstWhere('customer_id', $customerPublicId);
            $newLink = (bool) ($sent['link_sent'] ?? false);
        }

        return $this->corrections($loan)->firstWhere('id', $requestId) + ['new_link_sent' => $newLink];
    }

    /* Public: the customer on their link */

    /**
     * What the customer reads. Opening the link is recorded (first time only).
     *
     * @return array<string, mixed>
     */
    public function reviewData(string $token): array
    {
        $party = $this->findByToken($token);
        $status = $this->statusOf($party);

        if ($status === 'stale') {
            throw new BusinessRuleException('REVIEW_DETAILS_CHANGED', 'The details changed after you confirmed them. Your branch will send you a new link.', 410);
        }

        // Each load of the page is one open; when they are used up the branch has to send a new link.
        // (A customer already on the page can still ask for the code and confirm.)
        if ($status === 'pending') {
            if ($party->review_open_count >= $this->maxOpens()) {
                throw new BusinessRuleException('REVIEW_LINK_EXPIRED', 'This link has been opened too many times. Please ask your branch to send you a new one.', 410);
            }

            $party->forceFill(['review_open_count' => $party->review_open_count + 1, 'review_viewed_at' => $party->review_viewed_at ?? now()])->save();
        }

        return $this->payload($party, $status);
    }

    /**
     * What the customer sees, for an officer who shows it to a customer without a smartphone. Not an open
     * of the link and not recorded as a view.
     *
     * @return array<string, mixed>
     */
    public function customerDetails(Loan $loan, string $customerPublicId): array
    {
        $party = $this->partyOf($loan, $customerPublicId);

        return $this->payload($party, $this->statusOf($party));
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(LoanParty $party, string $status): array
    {
        $own = $this->corrections($this->rootOf($party))->where('customer.public_id', $party->customer->public_id);

        return [
            'status' => $status,
            'customer_phone_masked' => PhoneNormalizer::mask($party->customer->phone_primary),
            'link_expires_at' => null,
            'opens_left' => max(0, $this->maxOpens() - $party->review_open_count),
            'confirmed_at' => $party->review_confirmed_at?->toApiString(),
            'declaration_text' => self::DECLARATION,
            'details' => ['kyc' => $this->kycDetails($party), 'loan' => $this->loanDetails($party)],
            'corrections' => $own->map(fn ($c) => collect($c)->only(['id', 'stage', 'field_name', 'current_value', 'proposed_value', 'reason', 'status', 'submitted_at'])->all())->values()->all(),
        ];
    }

    protected function maxOpens(): int
    {
        return max(1, (int) Setting::value('loan_review.max_opens', 2));
    }

    /**
     * Send (or resend) the one code of this link.
     *
     * @return array<string, mixed>
     */
    public function requestOtp(string $token): array
    {
        $party = $this->findByToken($token);
        $this->assertLinkOpen($party);

        $max = (int) Setting::value('otp.max_sends', 3);
        $existing = $this->latestPending($party);

        if ($existing) {
            $challenge = $this->otp->resend($existing, $this->codeMessage($party));
        } else {
            $challenge = $this->issueCode($party, null, null);
        }

        return [
            'customer_phone_masked' => PhoneNormalizer::mask($party->customer->phone_primary),
            'otp_expires_at' => $challenge->expires_at->toApiString(),
            'otp_requests_left' => max(0, $max - (int) $challenge->fresh()->send_count),
            'sms_sent' => true,
        ];
    }

    /**
     * The customer confirms with the code.
     *
     * @return array<string, mixed>
     */
    public function confirm(string $token, string $code): array
    {
        $party = $this->findByToken($token);
        $this->assertLinkOpen($party);

        $challenge = $this->pendingCode($party);

        // Outside a transaction on purpose: a wrong code must stay counted (OtpService::verify).
        $this->otp->verify($challenge, $code, $this->hashFor($party));
        $this->markConfirmed($party, 'link', null);

        $root = $this->rootOf($party);
        $this->workflow->record($root, LoanActivityType::Update, null, "{$party->customer->customer_no} confirmed the loan details on the review link");
        $this->alertStaff($root, 'LOAN_REVIEW_CONFIRMED', 'Customer confirmed the loan details', "{$party->customer->customer_no} confirmed the details and terms of {$root->application_no} on the review link.");

        return [
            'status' => 'confirmed',
            'reference' => 'CONF-'.strtoupper(substr($challenge->public_id, -8)),
            'confirmed_at' => $party->review_confirmed_at->toApiString(),
        ];
    }

    /**
     * @param  array{stage: string, field_name: string, current_value?: string|null, proposed_value: string, reason: string}  $data
     * @return array<string, mixed>
     */
    public function requestCorrection(string $token, array $data): array
    {
        $party = $this->findByToken($token);
        $this->assertLinkOpen($party);

        $root = $this->rootOf($party);
        $open = $this->corrections($root)->where('customer.public_id', $party->customer->public_id)->where('status', 'pending_review')->count();

        if ($open >= self::MAX_OPEN_CORRECTIONS) {
            throw new BusinessRuleException('TOO_MANY_CORRECTIONS', 'You already have several correction requests waiting. Your loan officer will contact you.', 429);
        }

        $activity = $this->workflow->record($root, LoanActivityType::CorrectionRequest, null, "{$party->customer->customer_no} asks to correct {$data['field_name']}", [
            'customer_public_id' => $party->customer->public_id,
            'customer_no' => $party->customer->customer_no,
            'full_name' => $party->customer->full_name,
            'stage' => $data['stage'],
            'field_name' => $data['field_name'],
            'current_value' => $data['current_value'] ?? null,
            'proposed_value' => $data['proposed_value'],
            'reason' => $data['reason'],
        ]);

        $this->alertStaff(
            $root,
            'LOAN_CORRECTION_REQUESTED',
            'Customer asked for a correction',
            "{$party->customer->customer_no} asked to correct {$data['field_name']} on {$root->application_no}. Open the loan to answer.",
            ['correction_id' => $activity->id, 'field_name' => $data['field_name']],
        );

        return [
            'id' => $activity->id,
            'status' => 'pending_review',
            'message' => 'Your correction request has been logged and sent to your loan officer for verification.',
        ];
    }

    /* Shared */

    /**
     * pending / confirmed / expired / stale (confirmed, but the details changed since) / failed
     * (too many wrong codes) / not_sent.
     */
    public function statusOf(LoanParty $party): string
    {
        if ($party->review_confirmed_at !== null) {
            return hash_equals((string) $party->review_hash, $this->hashFor($party)) ? 'confirmed' : 'stale';
        }

        if ($party->review_token_hash === null) {
            return 'not_sent';
        }

        $locked = OtpChallenge::where('subject_type', $party->getMorphClass())->where('subject_id', $party->id)
            ->where('purpose', OtpPurpose::LoanReview->value)->latest('id')->value('status');

        return $locked === OtpStatus::Locked->value ? 'failed' : 'pending';
    }

    /**
     * True when this person's confirmation is current (used by the disbursement check).
     */
    public function isConfirmed(LoanParty $party): bool
    {
        return $this->statusOf($party) === 'confirmed';
    }

    /**
     * The people who confirm: every borrowing party still on the loan (every member, for a group).
     *
     * @return Collection<int, LoanParty>
     */
    public function recipients(Loan $loan): Collection
    {
        return LoanParty::with(['customer', 'loan.product'])
            ->whereIn('loan_id', $loan->idsWithMemberLoans())
            ->whereIn('role', PartyRole::borrowingValues())
            ->whereNull('released_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Tell the people working the loan (in-app notification): its officer and creator, and the branch staff who
     * manage offers. Only references go in the message, never personal data.
     *
     * @param  array<string, mixed>  $data
     */
    protected function alertStaff(Loan $loan, string $type, string $title, string $message, array $data = []): void
    {
        $own = User::whereIn('id', array_filter([$loan->assigned_officer_id, $loan->created_by]))->where('is_active', true)->get();
        $managers = $this->notifications->staffWithPermission('Loan Offer Manage', $loan->branch_id);

        $this->notifications->notifyStaff($own->merge($managers)->unique('id')->values(), $type, $title, $message, ['loan_public_id' => $loan->public_id, 'application_no' => $loan->application_no] + $data);
    }

    protected function assertOpen(Loan $loan): void
    {
        if (! in_array($loan->status, self::OPEN_STATUSES, true)) {
            throw new BusinessRuleException('REVIEW_NOT_OPEN', 'The review link is used after the application is submitted and before the loan is paid out.', 409);
        }
    }

    protected function assertConfirmable(LoanParty $party): void
    {
        if ($this->statusOf($party) === 'confirmed') {
            throw new BusinessRuleException('REVIEW_ALREADY_CONFIRMED', 'This customer has already confirmed the details.', 409);
        }
    }

    /**
     * The link must exist, be unexpired and not yet confirmed, on a loan still open.
     */
    protected function assertLinkOpen(LoanParty $party): void
    {
        $status = $this->statusOf($party);

        if ($status === 'confirmed') {
            throw new BusinessRuleException('REVIEW_ALREADY_CONFIRMED', 'You have already confirmed these details. Thank you.', 409);
        }

        if (in_array($status, ['expired', 'stale'], true)) {
            throw new BusinessRuleException('REVIEW_LINK_EXPIRED', 'This link is no longer valid. Please ask your branch to send you a new one.', 410);
        }

        if ($status === 'failed') {
            throw new BusinessRuleException('OTP_LOCKED', 'Too many wrong codes. Please ask your branch to send you a new link.', 422);
        }

        if (! in_array($party->loan->status, self::OPEN_STATUSES, true)) {
            throw new BusinessRuleException('REVIEW_LINK_EXPIRED', 'This loan application is no longer open for review.', 410);
        }
    }

    protected function findByToken(string $token): LoanParty
    {
        $party = strlen($token) >= 32
            ? LoanParty::with(['customer', 'loan.product'])->where('review_token_hash', $this->hashToken($token))->first()
            : null;

        if (! $party) {
            throw new BusinessRuleException('REVIEW_LINK_INVALID', 'This link is not valid.', 404);
        }

        return $party;
    }

    protected function partyOf(Loan $loan, string $customerPublicId): LoanParty
    {
        $party = $this->recipients($loan)->first(fn (LoanParty $p) => $p->customer->public_id === $customerPublicId);

        if (! $party) {
            throw new BusinessRuleException('NOT_A_BORROWER', 'That customer is not a borrower on this loan.', 404);
        }

        return $party;
    }

    /**
     * The loan the timeline is shown on: the group facility for a member loan.
     */
    protected function rootOf(LoanParty $party): Loan
    {
        $loan = $party->loan;

        return $loan->parent_id ? Loan::withoutGlobalScope('branch')->findOrFail($loan->parent_id) : $loan;
    }

    protected function issueCode(LoanParty $party, ?string $staffReason, ?User $by): OtpChallenge
    {
        $hash = $this->hashFor($party);
        $payload = ['application_no' => $party->loan->application_no, 'customer_no' => $party->customer->customer_no, 'review_hash' => $hash]
            + ($staffReason !== null ? ['staff_assist_reason' => $staffReason] : []);

        return $this->otp->issue(OtpPurpose::LoanReview, $party, $party->customer->phone_primary, $this->codeMessage($party), $payload, $hash, $by);
    }

    protected function codeMessage(LoanParty $party): string
    {
        $loan = $party->loan;

        return "CDP Capital: :code is your code to CONFIRM your details and loan {$loan->application_no} (Rs {$loan->decisionAmount()}, {$loan->tenure} installments). "
            .'Valid :minutes minutes. Never share this code with anyone except the person you are confirming with.';
    }

    protected function latestPending(LoanParty $party): ?OtpChallenge
    {
        return $this->otp->latestPending($party, OtpPurpose::LoanReview);
    }

    protected function pendingCode(LoanParty $party): OtpChallenge
    {
        return $this->latestPending($party)
            ?? throw new BusinessRuleException('OTP_NOT_REQUESTED', 'No code is waiting. Ask for a code first.', 422);
    }

    protected function markConfirmed(LoanParty $party, string $via, ?string $reason): void
    {
        $party->forceFill([
            'review_confirmed_at' => now(),
            'review_hash' => $this->hashFor($party),
            'review_confirmed_via' => $via,
            'review_reason' => $reason !== null ? Str::limit($reason, 250, '') : null,
        ])->save();
    }

    protected function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    protected function linkFor(string $token): string
    {
        return rtrim((string) (config('app.frontend_url') ?? config('app.url')), '/').'/loan-review/'.$token;
    }

    /**
     * Hash of what the customer confirms: their KYC details and the loan terms as they are now.
     */
    protected function hashFor(LoanParty $party): string
    {
        return hash('sha256', json_encode(['customer' => $party->customer->kycSnapshot(), 'terms' => $this->terms($party)]));
    }

    /**
     * The loan terms the person agrees to, in a fixed order.
     *
     * @return array<string, mixed>
     */
    protected function terms(LoanParty $party): array
    {
        $loan = $party->loan;
        $rootId = $loan->parent_id ?? $loan->id;

        $others = LoanParty::with('customer')->whereIn('loan_id', array_unique([$loan->id, $rootId]))->where('id', '!=', $party->id)->get();
        $customerNos = fn (Collection $parties) => $parties->map(fn (LoanParty $p) => $p->customer->customer_no)->sort()->values()->all();

        return [
            'application_no' => $loan->application_no,
            'product' => $loan->product->slug,
            'amount' => $loan->decisionAmount(),
            'rate' => (string) $loan->rate,
            'tenure' => $loan->tenure,
            'frequency' => $loan->repayment_frequency->value,
            'installment' => (string) $loan->installment_amount,
            'upfront' => (string) $loan->upfront_charges,
            'net' => (string) $loan->net_disbursement_amount,
            'guarantors' => $customerNos($others->filter(fn (LoanParty $p) => $p->role === PartyRole::Guarantor)),
            'co_borrowers' => $customerNos($others->filter(fn (LoanParty $p) => $p->role->isBorrowing())),
            'collaterals' => $loan->collaterals()->orderBy('id')->get(['type', 'market_value'])->map(fn ($c) => [$c->type, (string) $c->market_value])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function kycDetails(LoanParty $party): array
    {
        /** @var Customer $c */
        $c = $party->customer;
        $loan = $party->loan;

        return [
            'customer_id' => $c->public_id,
            'customer_code' => $c->customer_no,
            'full_name' => $c->full_name,
            'name_with_initials' => $c->name_with_initials,
            'id_type' => $c->id_type->value,
            'id_number' => Mask::idNumber($c->normalizedIdNumber() ?? (string) $c->id_number),
            'date_of_birth' => $c->date_of_birth->toDateString(),
            'phone_primary' => PhoneNormalizer::mask($c->phone_primary),
            'email' => Mask::email($c->email),
            'preferred_language' => $c->preferred_language->value,
            'address' => [
                'line_1' => $c->address_line_1,
                'line_2' => $c->address_line_2,
                'city' => $c->city,
                'postal_code' => $c->postal_code,
                'state' => $c->province,
            ],
            'employment' => [
                'status' => $c->employment_status->value,
                'occupation' => $c->occupation,
                'employer_name' => $c->employer_name,
                'employer_phone' => PhoneNormalizer::mask($c->employer_phone),
                'monthly_income' => $loan->assessed_monthly_income !== null ? (float) $loan->assessed_monthly_income : null,
            ],
            'financials' => [
                'total_monthly_income' => $loan->assessed_monthly_income !== null ? (float) $loan->assessed_monthly_income : null,
                'total_monthly_expenses' => $loan->assessed_monthly_expenses !== null ? (float) $loan->assessed_monthly_expenses : null,
            ],
            'region_details' => [
                'gn_division' => $c->gn_division,
                'ds_division' => $c->ds_division,
                'district' => $c->district,
                'province' => $c->province,
            ],
            'bank_details' => $c->bankAccounts()->where('is_active', true)->orderByDesc('is_primary')->get()->map(fn ($a) => [
                'bank_name' => $a->bank_name,
                'branch_name' => $a->branch_name,
                'account_number' => $a->maskedNumber(),
                'account_name' => $a->account_holder_name,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function loanDetails(LoanParty $party): array
    {
        $loan = $party->loan;
        $rootId = $loan->parent_id ?? $loan->id;
        $others = LoanParty::with('customer')->whereIn('loan_id', array_unique([$loan->id, $rootId]))->where('id', '!=', $party->id)->get();

        return [
            'id' => $loan->public_id,
            'reference' => $loan->application_no,
            'product' => $loan->product->name,
            'loan_type' => $loan->borrower_type->value,
            'requested_amount' => $loan->decisionAmount(),
            'interest_rate' => (string) $loan->rate,
            'interest_type' => $loan->pricing_method->value,
            'term_months' => $loan->tenure,
            'repayment_frequency' => $loan->repayment_frequency->value,
            'monthly_installment' => $loan->installment_amount !== null ? (string) $loan->installment_amount : null,
            'processing_fee' => (string) $loan->upfront_charges,
            'net_disbursement_amount' => $loan->net_disbursement_amount !== null ? (string) $loan->net_disbursement_amount : null,
            'monthly_repayment_date' => $loan->first_due_date ? (int) $loan->first_due_date->format('j') : null,
            'co_borrowers' => $others->filter(fn (LoanParty $p) => $p->role->isBorrowing())->map(fn (LoanParty $p) => ['customer_id' => $p->customer->public_id, 'full_name' => $p->customer->full_name])->values()->all(),
            'guarantors' => $others->filter(fn (LoanParty $p) => $p->role === PartyRole::Guarantor)->map(fn (LoanParty $p) => ['full_name' => $p->customer->full_name, 'type' => $p->relationship ? Str::headline($p->relationship) : 'Guarantor'])->values()->all(),
            'securities' => $loan->collaterals()->orderBy('id')->get()->map(fn ($c) => [
                'type' => Str::headline($c->type),
                'description' => $c->description,
                'owner_deed_number' => $c->details['deed_no'] ?? null,
                'registration_number' => $c->details['registration_no'] ?? null,
            ])->all(),
        ];
    }
}

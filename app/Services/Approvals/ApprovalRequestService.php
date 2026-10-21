<?php

namespace App\Services\Approvals;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Accounting\Approvals\ManualJournalHandler;
use App\Services\Approvals\Contracts\ApprovalHandler;
use App\Services\Catalog\Approvals\ProductShariahApprovalHandler;
use App\Services\Catalog\Approvals\ProductTermsChangeHandler;
use App\Services\Loans\Approvals\BackdatedPaymentHandler;
use App\Services\Loans\Approvals\PaymentReversalHandler;
use App\Services\Loans\Approvals\PenaltyWaiverHandler;
use App\Services\Loans\Approvals\RestructureHandler;
use App\Services\Loans\Approvals\WriteOffHandler;
use App\Traits\ActivityLogTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Maker-checker for every sensitive change.
 *
 * Rules that hold for every action:
 * - the person who asked can never decide their own request;
 * - the decider needs the permission of that action (not a role name);
 * - a request is decided once; the row is locked while deciding so two approvers clicking at the
 *   same moment cannot both apply the change.
 */
class ApprovalRequestService
{
    use ActivityLogTrait;

    /**
     * @var array<string, class-string<ApprovalHandler>>
     */
    protected const HANDLERS = [
        ApprovalAction::ProductShariahApproval->value => ProductShariahApprovalHandler::class,
        ApprovalAction::ProductTermsChange->value => ProductTermsChangeHandler::class,
        ApprovalAction::PaymentReversal->value => PaymentReversalHandler::class,
        ApprovalAction::BackdatedPayment->value => BackdatedPaymentHandler::class,
        ApprovalAction::PenaltyWaiver->value => PenaltyWaiverHandler::class,
        ApprovalAction::Restructure->value => RestructureHandler::class,
        ApprovalAction::ManualJournal->value => ManualJournalHandler::class,
        ApprovalAction::WriteOff->value => WriteOffHandler::class,
    ];

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function create(Model $subject, ApprovalAction $action, ?array $payload, string $reason, User $requester): ApprovalRequest
    {
        $request = ApprovalRequest::create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            // Loans carry their branch; a payment takes its loan's. Products have none (head office).
            'branch_id' => $subject->getAttribute('branch_id') ?? $subject->loan?->branch_id,
            'action' => $action,
            'payload' => $payload,
            'reason' => $reason,
            'requested_by' => $requester->id,
        ]);

        $this->logActivity('APPROVAL_REQUESTED', 'ApprovalRequest', "Requested {$action->value} ({$request->public_id})", [
            'subject_type' => $request->subject_type,
            'subject_id' => $request->subject_id,
        ]);

        return $request;
    }

    /**
     * One open request per action per record, so two people cannot ask for the same change twice.
     */
    public function assertNonePending(Model $subject, ApprovalAction $action): void
    {
        $pending = ApprovalRequest::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('action', $action->value)
            ->pending()
            ->exists();

        if ($pending) {
            throw new BusinessRuleException('APPROVAL_ALREADY_PENDING', 'A request for this is already waiting for approval.');
        }
    }

    public function approve(ApprovalRequest $request, User $approver, ?string $reference, ?string $remarks): ApprovalRequest
    {
        return $this->decide($request, $approver, ApprovalStatus::Approved, $reference, $remarks);
    }

    public function reject(ApprovalRequest $request, User $approver, string $remarks): ApprovalRequest
    {
        return $this->decide($request, $approver, ApprovalStatus::Rejected, null, $remarks);
    }

    /**
     * The requester can withdraw their own request while it is pending.
     */
    public function cancel(ApprovalRequest $request, User $user): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $user) {
            $request = ApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($request);

            if ($request->requested_by !== $user->id) {
                throw new BusinessRuleException('NOT_REQUESTER', 'Only the person who made the request can cancel it.', 403);
            }

            // A cancelled Shariah review sends the product back to draft, same as a rejection.
            $this->handlerFor($request->action)->rejected($request, $user);

            $request->forceFill([
                'status' => ApprovalStatus::Cancelled,
                'decided_by' => $user->id,
                'decided_at' => now(),
            ])->save();

            return $request;
        });
    }

    protected function decide(ApprovalRequest $request, User $approver, ApprovalStatus $decision, ?string $reference, ?string $remarks): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $approver, $decision, $reference, $remarks) {
            $request = ApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($request);

            if ($request->requested_by === $approver->id) {
                throw new BusinessRuleException('SELF_APPROVAL_NOT_ALLOWED', 'You cannot decide a request you made yourself.', 403);
            }

            if (! $approver->can($request->action->permission())) {
                throw new BusinessRuleException('APPROVAL_PERMISSION_REQUIRED', 'You do not have permission to decide this request.', 403, [
                    'required' => $request->action->permission(),
                ]);
            }

            if ($decision === ApprovalStatus::Approved && $request->action->requiresReference() && blank($reference)) {
                throw new BusinessRuleException('APPROVAL_REFERENCE_REQUIRED', 'The Shariah board resolution / reference number is required.', 422);
            }

            // Set the decision before calling the handler so it can read the reference.
            $request->forceFill([
                'status' => $decision,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'decision_reference' => $reference,
                'decision_remarks' => $remarks,
            ]);

            $handler = $this->handlerFor($request->action);

            $decision === ApprovalStatus::Approved
                ? $handler->approved($request, $approver)
                : $handler->rejected($request, $approver);

            $request->save();

            $this->logActivity(strtoupper($decision->value), 'ApprovalRequest', "{$decision->value} {$request->action->value} ({$request->public_id})", [
                'reference' => $reference,
                'remarks' => $remarks,
            ]);

            return $request;
        });
    }

    protected function assertPending(ApprovalRequest $request): void
    {
        if (! $request->isPending()) {
            throw new BusinessRuleException('APPROVAL_ALREADY_DECIDED', 'This request has already been decided.');
        }
    }

    protected function handlerFor(ApprovalAction $action): ApprovalHandler
    {
        return app(self::HANDLERS[$action->value]);
    }
}

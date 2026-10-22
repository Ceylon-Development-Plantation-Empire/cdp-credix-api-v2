<?php

declare(strict_types=1);

namespace App\Services\Loans\Approvals;

use App\Models\ApprovalRequest;
use App\Models\Loan;
use App\Models\User;
use App\Services\Approvals\Contracts\ApprovalHandler;
use App\Services\Loans\RepaymentService;

/**
 * A payment dated further back than payment.max_backdate_days is posted only once approved,
 * with the original Idempotency-Key, so approving twice can never post it twice.
 */
class BackdatedPaymentHandler implements ApprovalHandler
{
    public function __construct(
        protected RepaymentService $repayments,
    ) {}

    public function approved(ApprovalRequest $request, User $approver): void
    {
        $loan = Loan::withoutGlobalScope('branch')->findOrFail($request->subject_id);
        $requester = User::findOrFail($request->requested_by);

        $this->repayments->post($loan, $request->payload, $request->payload['idempotency_key'], $requester, $approver);
    }

    public function rejected(ApprovalRequest $request, User $approver): void
    {
        // The payment was never posted.
    }
}

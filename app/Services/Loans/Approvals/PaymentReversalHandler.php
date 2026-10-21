<?php

namespace App\Services\Loans\Approvals;

use App\Models\ApprovalRequest;
use App\Models\LoanTransaction;
use App\Models\User;
use App\Services\Approvals\Contracts\ApprovalHandler;
use App\Services\Loans\RepaymentService;

/**
 * A second person confirmed the reversal: the contra entry is posted now.
 * (The request is only visible to approvers who can see the loan's branch.)
 */
class PaymentReversalHandler implements ApprovalHandler
{
    public function __construct(
        protected RepaymentService $repayments,
    ) {}

    public function approved(ApprovalRequest $request, User $approver): void
    {
        $this->repayments->reverse(LoanTransaction::findOrFail($request->subject_id), $request->reason, $approver, $request->requested_by);
    }

    public function rejected(ApprovalRequest $request, User $approver): void
    {
        // Nothing was changed while the request waited.
    }
}

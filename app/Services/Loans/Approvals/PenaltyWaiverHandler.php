<?php

declare(strict_types=1);

namespace App\Services\Loans\Approvals;

use App\Models\ApprovalRequest;
use App\Models\Loan;
use App\Models\User;
use App\Services\Approvals\Contracts\ApprovalHandler;
use App\Services\Loans\LoanWorkflowService;
use App\Services\Loans\PenaltyService;

class PenaltyWaiverHandler implements ApprovalHandler
{
    public function __construct(
        protected PenaltyService $penalties,
        protected LoanWorkflowService $workflow,
    ) {}

    public function approved(ApprovalRequest $request, User $approver): void
    {
        $loan = $this->workflow->lock(Loan::withoutGlobalScope('branch')->findOrFail($request->subject_id));

        $this->penalties->applyWaiver($loan, (int) $request->payload['installment_no'], $request->payload['amount'], $request->reason, $approver, $request->requested_by);
    }

    public function rejected(ApprovalRequest $request, User $approver): void
    {
        // The penalty stays owed.
    }
}

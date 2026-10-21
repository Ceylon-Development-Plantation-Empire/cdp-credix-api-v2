<?php

namespace App\Services\Loans\Approvals;

use App\Models\ApprovalRequest;
use App\Models\Loan;
use App\Models\User;
use App\Services\Approvals\Contracts\ApprovalHandler;
use App\Services\Loans\RestructureService;

class RestructureHandler implements ApprovalHandler
{
    public function __construct(
        protected RestructureService $restructures,
    ) {}

    public function approved(ApprovalRequest $request, User $approver): void
    {
        $this->restructures->apply(Loan::withoutGlobalScope('branch')->findOrFail($request->subject_id), $request->payload, $approver, $request->reason);
    }

    public function rejected(ApprovalRequest $request, User $approver): void
    {
        // The current schedule stays in force; payments can be posted again.
    }
}

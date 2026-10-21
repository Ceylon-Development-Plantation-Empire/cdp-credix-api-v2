<?php

namespace App\Services\Loans\Approvals;

use App\Models\ApprovalRequest;
use App\Models\Loan;
use App\Models\User;
use App\Services\Approvals\Contracts\ApprovalHandler;
use App\Services\Loans\WriteOffService;

class WriteOffHandler implements ApprovalHandler
{
    public function __construct(
        protected WriteOffService $writeOffs,
    ) {}

    public function approved(ApprovalRequest $request, User $approver): void
    {
        $loan = Loan::withoutGlobalScope('branch')->findOrFail($request->subject_id);

        $this->writeOffs->apply($loan, $request->reason, $approver, $request->requested_by);
    }

    public function rejected(ApprovalRequest $request, User $approver): void
    {
        // The loan stays on the books and collection goes on.
    }
}

<?php

namespace App\Services\Approvals\Contracts;

use App\Models\ApprovalRequest;
use App\Models\User;

/**
 * What happens to the subject when a request is decided. One handler per ApprovalAction.
 * Both methods run inside the decision's database transaction.
 */
interface ApprovalHandler
{
    public function approved(ApprovalRequest $request, User $approver): void;

    public function rejected(ApprovalRequest $request, User $approver): void;
}

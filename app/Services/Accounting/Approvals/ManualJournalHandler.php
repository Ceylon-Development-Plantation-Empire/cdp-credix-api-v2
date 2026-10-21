<?php

namespace App\Services\Accounting\Approvals;

use App\Models\ApprovalRequest;
use App\Models\GlJournal;
use App\Models\User;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Approvals\Contracts\ApprovalHandler;

/**
 * A manual journal counts in the balances only once a second person approves it.
 */
class ManualJournalHandler implements ApprovalHandler
{
    public function __construct(
        protected GeneralLedgerService $gl,
    ) {}

    public function approved(ApprovalRequest $request, User $approver): void
    {
        $this->gl->decideManual($this->journal($request), true, $approver);
    }

    public function rejected(ApprovalRequest $request, User $approver): void
    {
        $this->gl->decideManual($this->journal($request), false, $approver);
    }

    protected function journal(ApprovalRequest $request): GlJournal
    {
        return GlJournal::withoutGlobalScope('branch')->findOrFail($request->subject_id);
    }
}

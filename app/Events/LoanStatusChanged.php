<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\LoanStatus;
use App\Models\Loan;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by LoanWorkflowService for every status change. Delivered only after the database
 * transaction commits, so a step that is rolled back never sends a message.
 */
class LoanStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Loan $loan,
        public ?LoanStatus $from,
        public LoanStatus $to,
    ) {}
}

<?php

namespace App\Events;

use App\Models\LoanTransaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public LoanTransaction $transaction,
    ) {}
}

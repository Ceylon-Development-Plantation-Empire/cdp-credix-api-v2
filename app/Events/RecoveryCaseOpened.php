<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\RecoveryCase;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class RecoveryCaseOpened implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public RecoveryCase $case,
    ) {}
}

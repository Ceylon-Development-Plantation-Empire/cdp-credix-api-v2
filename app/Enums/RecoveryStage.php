<?php

namespace App\Enums;

/**
 * notice   → 90 DPD: recovery notice to borrower and guarantors
 * internal → 180 DPD: an internal recovery agent works the case
 * external → handed to an external recovery agency
 * legal    → legal action
 */
enum RecoveryStage: string
{
    case Notice = 'notice';
    case Internal = 'internal';
    case External = 'external';
    case Legal = 'legal';

    public function isAgentStage(): bool
    {
        return in_array($this, [self::Internal, self::External], true);
    }
}

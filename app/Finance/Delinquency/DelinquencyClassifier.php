<?php

declare(strict_types=1);

namespace App\Finance\Delinquency;

use Carbon\CarbonImmutable;

/**
 * Days past due and risk stage (decision D-3).
 *
 * DPD is counted from the due date of the oldest installment that is still not fully paid.
 * Counting days (not missed installments) treats weekly and monthly loans the same way.
 *
 * Stages follow the SLFRS 9 / CBSL convention used by banks and finance companies:
 *   Stage 1  0 – 30 DPD    performing
 *   Stage 2  31 – 90 DPD   significant increase in credit risk
 *   Stage 3  over 90 DPD   non-performing (credit impaired)
 */
class DelinquencyClassifier
{
    public function __construct(
        protected int $stageTwoAfterDays = 30,
        protected int $stageThreeAfterDays = 90,
    ) {}

    public function daysPastDue(?CarbonImmutable $oldestUnpaidDueDate, CarbonImmutable $businessDate): int
    {
        if ($oldestUnpaidDueDate === null) {
            return 0;
        }

        $due = $oldestUnpaidDueDate->startOfDay();
        $today = $businessDate->startOfDay();

        return $today->greaterThan($due) ? (int) $due->diffInDays($today) : 0;
    }

    public function riskStage(int $daysPastDue): int
    {
        return match (true) {
            $daysPastDue > $this->stageThreeAfterDays => 3,
            $daysPastDue > $this->stageTwoAfterDays => 2,
            default => 1,
        };
    }

    public function isNonPerforming(int $daysPastDue): bool
    {
        return $this->riskStage($daysPastDue) === 3;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Recovery;

use App\Enums\LoanActivityType;
use App\Enums\LoanStatus;
use App\Enums\RecoveryStage;
use App\Enums\RecoveryStatus;
use App\Enums\UserType;
use App\Events\RecoveryCaseOpened;
use App\Exceptions\BusinessRuleException;
use App\Models\Loan;
use App\Support\BusinessDate;
use App\Models\LoanActivity;
use App\Models\RecoveryCase;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\BranchAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Recovery (design 6.5, decision D-3), measured in days past due:
 *   recovery.notice_dpd (90)        → case opened at "notice"; notice to borrowers and guarantors
 *   recovery.assign_agent_dpd (180) → stage "internal"; recovery managers are alerted to assign an agent
 * A recovery manager may hand a case to an external agency (a user with user_type = agency) or
 * move it to legal. Cases resolve when the arrears are cleared and close with the loan
 * (LoanBalanceService and LoanWorkflowService).
 */
class RecoveryService
{
    public const ASSIGN_PERMISSION = 'Recovery Case Assign';

    public const WORK_PERMISSION = 'Recovery Case Work';

    public function __construct(
        protected NotificationService $notifications,
    ) {}

    /**
     * Run by the nightly job after the loan's DPD is rebuilt. Safe to run again for the same day.
     */
    public function evaluate(Loan $loan, CarbonImmutable $businessDate): ?RecoveryCase
    {
        if ($loan->status !== LoanStatus::Active) {
            return null;
        }

        $noticeDpd = (int) Setting::value('recovery.notice_dpd', 90);
        $agentDpd = (int) Setting::value('recovery.assign_agent_dpd', 180);
        $case = RecoveryCase::withoutGlobalScope('branch')->where('loan_id', $loan->id)->open()->first();

        if (! $case && $loan->days_past_due >= $noticeDpd) {
            $case = $this->open($loan, $businessDate);
        }

        if ($case && $case->stage === RecoveryStage::Notice && $loan->days_past_due >= $agentDpd) {
            $case->forceFill(['stage' => RecoveryStage::Internal])->save();
            $this->recordOnLoan($case, null, "Recovery case {$case->case_no} moved to internal recovery at {$loan->days_past_due} days past due; an agent must be assigned");

            $this->notifications->notifyStaff(
                $this->notifications->staffWithPermission(self::ASSIGN_PERMISSION, $loan->branch_id),
                'RECOVERY_AGENT_NEEDED',
                'Assign a recovery agent',
                "Loan {$loan->loan_no} is {$loan->days_past_due} days past due. Assign an agent to case {$case->case_no}.",
                ['case_public_id' => $case->public_id],
            );
        }

        return $case;
    }

    /**
     * Assign an internal agent (staff with Recovery Case Work in the case's branch) or an
     * external agency login.
     */
    public function assign(RecoveryCase $case, User $agent, RecoveryStage $stage, User $by): RecoveryCase
    {
        return DB::transaction(function () use ($case, $agent, $stage, $by) {
            $case = RecoveryCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($case);

            if (! $stage->isAgentStage()) {
                throw new BusinessRuleException('RECOVERY_STAGE_INVALID', 'An agent is assigned at the internal or external stage.', 422);
            }

            $valid = $stage === RecoveryStage::External
                ? $agent->user_type === UserType::Agency->value
                : in_array($agent->user_type, [UserType::Staff->value, UserType::Admin->value], true)
                    && $agent->can(self::WORK_PERMISSION)
                    && BranchAccess::canAccessBranch($case->branch_id, $agent);

            if (! $valid || ! $agent->is_active) {
                throw new BusinessRuleException('RECOVERY_AGENT_INVALID', $stage === RecoveryStage::External
                    ? 'An external case is assigned to an active recovery agency login.'
                    : 'An internal case is assigned to an active staff member of the branch with Recovery Case Work.', 422);
            }

            $case->forceFill(['stage' => $stage, 'assigned_user_id' => $agent->id, 'assigned_at' => now()])->save();
            $this->recordOnLoan($case, $by, "Case {$case->case_no} assigned to {$agent->name} ({$stage->value})");

            $this->notifications->notifyStaff($agent, 'RECOVERY_CASE_ASSIGNED', 'Recovery case assigned', "Case {$case->case_no} has been assigned to you.", [
                'case_public_id' => $case->public_id,
            ]);

            return $case;
        });
    }

    /**
     * Open a case by hand for a loan that is in arrears and has none open.
     */
    public function openManually(Loan $loan, string $remarks, User $by): RecoveryCase
    {
        if ($loan->status !== LoanStatus::Active || (int) $loan->days_past_due <= 0) {
            throw new BusinessRuleException('RECOVERY_NOT_IN_ARREARS', 'A recovery case is opened for an active loan that is past due.', 422);
        }

        if (RecoveryCase::withoutGlobalScope('branch')->where('loan_id', $loan->id)->open()->exists()) {
            throw new BusinessRuleException('RECOVERY_CASE_EXISTS', 'This loan already has an open recovery case.', 409);
        }

        $case = $this->open($loan, BusinessDate::today()->toImmutable());

        $this->recordOnLoan($case, $by, "Case {$case->case_no} opened by hand: {$remarks}");

        return $case;
    }

    /**
     * Close an open case by decision (not because the arrears cleared). The case and its history stay.
     */
    public function close(RecoveryCase $case, string $remarks, User $by): RecoveryCase
    {
        return DB::transaction(function () use ($case, $remarks, $by) {
            $case = RecoveryCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($case);

            $case->forceFill(['status' => RecoveryStatus::Closed, 'closed_at' => now(), 'open_case_key' => null])->save();
            $this->recordOnLoan($case, $by, "Case {$case->case_no} closed: {$remarks}");

            return $case;
        });
    }

    /**
     * Correct a call, visit, promise to pay or note. Only its author, only while the case is open.
     *
     * @param  array{type: string, remarks: string, promised_amount?: string|null, promised_date?: string|null}  $data
     */
    public function updateActivity(RecoveryCase $case, int $activityId, array $data, User $by): LoanActivity
    {
        $this->assertOpen($case);
        $activity = $this->ownActivity($case, $activityId, $by);

        if ($activity->type->value !== $data['type']) {
            throw new BusinessRuleException('ACTIVITY_TYPE_FIXED', 'The kind of activity cannot change; delete it and record a new one.', 422);
        }

        $activity->forceFill([
            'remarks' => $data['remarks'],
            'promised_amount' => $data['promised_amount'] ?? null,
            'promised_date' => $data['promised_date'] ?? null,
        ])->save();

        return $activity;
    }

    public function deleteActivity(RecoveryCase $case, int $activityId, User $by): void
    {
        $this->assertOpen($case);
        $this->ownActivity($case, $activityId, $by)->delete();
    }

    protected function ownActivity(RecoveryCase $case, int $activityId, User $by): LoanActivity
    {
        $activity = LoanActivity::where('recovery_case_id', $case->id)
            ->where('performed_by', $by->id)
            ->whereIn('type', array_map(fn (LoanActivityType $t) => $t->value, LoanActivity::EDITABLE_TYPES))
            ->find($activityId);

        if (! $activity) {
            throw new BusinessRuleException('ACTIVITY_NOT_FOUND', 'Activity not found, or it was written by someone else.', 404);
        }

        return $activity;
    }

    public function moveToLegal(RecoveryCase $case, string $remarks, User $by): RecoveryCase
    {
        return DB::transaction(function () use ($case, $remarks, $by) {
            $case = RecoveryCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($case);

            $case->forceFill(['stage' => RecoveryStage::Legal])->save();
            $this->recordOnLoan($case, $by, "Case {$case->case_no} moved to legal: {$remarks}");

            return $case;
        });
    }

    /**
     * A call, visit, promise to pay or note by the agent working the case. An agency may only work
     * the cases assigned to it.
     *
     * @param  array{type: string, remarks: string, promised_amount?: string|null, promised_date?: string|null}  $data
     */
    public function addActivity(RecoveryCase $case, array $data, User $by): LoanActivity
    {
        $this->assertOpen($case);

        if ($by->user_type === UserType::Agency->value && $case->assigned_user_id !== $by->id) {
            throw new BusinessRuleException('RECOVERY_CASE_NOT_ASSIGNED', 'This case is not assigned to you.', 403);
        }

        return LoanActivity::create([
            'loan_id' => $case->loan_id,
            'recovery_case_id' => $case->id,
            'type' => LoanActivityType::from($data['type']),
            'remarks' => $data['remarks'],
            'promised_amount' => $data['promised_amount'] ?? null,
            'promised_date' => $data['promised_date'] ?? null,
            'performed_by' => $by->id,
            'performed_at' => now(),
        ]);
    }

    protected function open(Loan $loan, CarbonImmutable $businessDate): ?RecoveryCase
    {
        try {
            $case = new RecoveryCase([
                'loan_id' => $loan->id,
                'branch_id' => $loan->branch_id,
                'opened_dpd' => $loan->days_past_due,
                'opened_arrears' => (string) $loan->arrears_amount,
                'opened_at' => $businessDate->endOfDay()->min(now()),
            ]);

            $case->forceFill([
                'stage' => RecoveryStage::Notice,
                'status' => RecoveryStatus::Open,
                'open_case_key' => $loan->id,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Another run opened it a moment ago.
            return RecoveryCase::withoutGlobalScope('branch')->where('loan_id', $loan->id)->open()->first();
        }

        $this->recordOnLoan($case, null, "Recovery case {$case->case_no} opened at {$loan->days_past_due} days past due; notice sent");
        RecoveryCaseOpened::dispatch($case);

        return $case;
    }

    protected function recordOnLoan(RecoveryCase $case, ?User $by, string $remarks): void
    {
        LoanActivity::create([
            'loan_id' => $case->loan_id,
            'recovery_case_id' => $case->id,
            'type' => LoanActivityType::Note,
            'remarks' => $remarks,
            'performed_by' => $by?->id,
            'performed_at' => now(),
        ]);
    }

    protected function assertOpen(RecoveryCase $case): void
    {
        if (! $case->isOpen()) {
            throw new BusinessRuleException('RECOVERY_CASE_CLOSED', 'This recovery case is no longer open.', 409);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\ClosureReason;
use App\Enums\CollateralStatus;
use App\Enums\LoanActivityType;
use App\Enums\LoanStatus;
use App\Enums\PartyRole;
use App\Enums\RecoveryStatus;
use App\Events\LoanStatusChanged;
use App\Exceptions\BusinessRuleException;
use App\Models\Loan;
use App\Models\LoanActivity;
use App\Models\LoanParty;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only code that changes a loan's status (design section 5.1).
 *
 * - Moves are checked against LoanStatus::allowedTransitions().
 * - Every move writes the loan's timeline in the same transaction, which is also how the
 *   maker-checker rules know who reviewed, verified and approved.
 * - A group facility moves with all of its member loans, so they can never disagree
 *   (bug B-7 of the old system).
 * - Reaching the "open" status claims open_loan_key for every borrower (rule L-1, enforced by a
 *   unique index); closing releases the keys, the guarantees and the collateral.
 *
 * Callers lock the loan row (lock()) inside their own DB::transaction before calling transition().
 */
class LoanWorkflowService
{
    /**
     * Timestamp column stamped when a loan reaches a status.
     */
    protected const STAMPS = [
        'submitted' => 'submitted_at',
        'approved' => 'approved_at',
        'accepted' => 'accepted_at',
        'active' => 'disbursed_at',
    ];

    /**
     * Re-read the loan with a row lock. Every workflow action starts here.
     */
    public function lock(Loan $loan): Loan
    {
        return Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $metadata  Stored on the timeline entry (ids of what was checked …).
     */
    public function transition(Loan $loan, LoanStatus $to, ?User $by, ?string $remarks = null, array $metadata = [], ?ClosureReason $reason = null): Loan
    {
        if ($loan->isMemberLoan()) {
            throw new BusinessRuleException('MEMBER_LOAN_ACTION', 'This is a member loan of a group facility. Work on the group facility instead.', 422);
        }

        $from = $loan->status;

        if (! $from->canMoveTo($to)) {
            throw new BusinessRuleException('LOAN_INVALID_TRANSITION', "A loan that is {$from->value} cannot be moved to {$to->value}.", 409, [
                'status' => $from->value,
                'allowed' => array_map(fn (LoanStatus $s) => $s->value, $from->allowedTransitions()),
            ]);
        }

        $loans = $loan->isGroupFacility()
            ? collect([$loan])->merge($loan->memberLoans()->whereNotIn('status', LoanStatus::closedValues())->lockForUpdate()->get())
            : collect([$loan]);

        foreach ($loans as $each) {
            $this->move($each, $from, $to, $by, $remarks, $metadata, $reason);
        }

        // Sent back after a decision was given: the approval, the offer acceptance and the consents were given on the
        // old figures, so they are voided and the application goes through the decisions again.
        if ($to === LoanStatus::Returned && in_array($from, [LoanStatus::Verified, LoanStatus::Approved, LoanStatus::Accepted], true)) {
            $this->voidDecisions($loans->pluck('id')->all());
        }

        // Delivered after commit: customers are told only about changes that were saved.
        LoanStatusChanged::dispatch($loan, $from, $to);

        if ($to === $this->openFrom()) {
            $this->claimOpenLoanKeys($loans->pluck('id')->all());
        }

        if ($this->releasesOnClose($to)) {
            $this->releaseSecurities($loans->pluck('id')->all(), $by);
        }

        return $loan->refresh();
    }

    /**
     * Close a running loan (fully paid, early settled, written off).
     *
     * Each member of a group pays their own loan, so a member loan closes on its own; the group
     * facility closes once every member loan is closed (written off if any member was).
     */
    public function closeLoan(Loan $loan, LoanStatus $to, ?User $by, ?string $remarks, ClosureReason $reason): void
    {
        if (! $loan->isMemberLoan()) {
            $by
                ? $this->transition($loan, $to, $by, $remarks, [], $reason)
                : $this->closeWithoutUser($loan, $to, $remarks, $reason);

            return;
        }

        if (! $loan->status->canMoveTo($to)) {
            throw new BusinessRuleException('LOAN_INVALID_TRANSITION', "A loan that is {$loan->status->value} cannot be moved to {$to->value}.", 409);
        }

        $from = $loan->status;
        $this->move($loan, $from, $to, $by, $remarks, [], $reason);

        if ($this->releasesOnClose($to)) {
            $this->releaseSecurities([$loan->id], $by);
        }

        LoanStatusChanged::dispatch($loan, $from, $to);

        // The facility closing is bookkeeping only: each member was already told about their own loan.
        $facility = Loan::withoutGlobalScope('branch')->whereKey($loan->parent_id)->lockForUpdate()->first();
        $open = $facility->memberLoans()->withoutGlobalScope('branch')->whereNotIn('status', LoanStatus::closedValues())->exists();

        if (! $open && $facility->status === LoanStatus::Active) {
            $writtenOff = $facility->memberLoans()->withoutGlobalScope('branch')->where('status', LoanStatus::WrittenOff->value)->exists();
            $final = $writtenOff ? LoanStatus::WrittenOff : LoanStatus::Settled;

            $this->move($facility, LoanStatus::Active, $final, $by, 'Every member loan is closed', [], $writtenOff ? ClosureReason::WrittenOff : ClosureReason::Settled);

            if ($this->releasesOnClose($final)) {
                $this->releaseSecurities([$facility->id], $by);
            }
        }
    }

    /**
     * Closing done by the system (the nightly run), with no user to record.
     */
    protected function closeWithoutUser(Loan $loan, LoanStatus $to, ?string $remarks, ClosureReason $reason): void
    {
        if (! $loan->status->canMoveTo($to)) {
            throw new BusinessRuleException('LOAN_INVALID_TRANSITION', "A loan that is {$loan->status->value} cannot be moved to {$to->value}.", 409);
        }

        $from = $loan->status;
        $this->move($loan, $from, $to, null, $remarks, [], $reason);

        if ($this->releasesOnClose($to)) {
            $this->releaseSecurities([$loan->id], null);
        }
        LoanStatusChanged::dispatch($loan, $from, $to);
    }

    /**
     * A member taken off a group application before approval. Their member loan is cancelled
     * (not deleted) so the history of what was applied for stays.
     */
    public function cancelMemberLoan(Loan $memberLoan, User $by, string $remarks): void
    {
        $from = $memberLoan->status;

        if (! $from->canMoveTo(LoanStatus::Cancelled)) {
            throw new BusinessRuleException('LOAN_INVALID_TRANSITION', "A member loan that is {$from->value} cannot be cancelled.", 409);
        }

        $this->move($memberLoan, $from, LoanStatus::Cancelled, $by, $remarks, [], ClosureReason::Cancelled);
        $this->releaseSecurities([$memberLoan->id], $by);
    }

    /**
     * A free-text entry on the timeline (notes, document checks, party changes …).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(Loan $loan, LoanActivityType $type, ?User $by, ?string $remarks, array $metadata = []): LoanActivity
    {
        return LoanActivity::create([
            'loan_id' => $loan->id,
            'type' => $type,
            'remarks' => $remarks,
            'metadata' => $metadata ?: null,
            'performed_by' => $by?->id,
            'performed_at' => now(),
        ]);
    }

    /**
     * The status from which a loan counts as open for "one open loan per person" (D-1).
     */
    public function openFrom(): LoanStatus
    {
        return Setting::value('loan.open_loan_scope', 'submitted') === 'approved' ? LoanStatus::Approved : LoanStatus::Submitted;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function move(Loan $loan, LoanStatus $from, LoanStatus $to, ?User $by, ?string $remarks, array $metadata, ?ClosureReason $reason): void
    {
        $changes = ['status' => $to, 'updated_by' => $by?->id];

        if (isset(self::STAMPS[$to->value])) {
            $changes[self::STAMPS[$to->value]] = now();
        }

        if ($to->isClosed()) {
            $changes['closed_at'] = now();
            $changes['closure_reason'] = $reason ?? match ($to) {
                LoanStatus::Settled => ClosureReason::Settled,
                LoanStatus::Rejected => ClosureReason::Rejected,
                LoanStatus::WrittenOff => ClosureReason::WrittenOff,
                default => ClosureReason::Cancelled,
            };
        }

        $loan->forceFill($changes)->save();

        LoanActivity::create([
            'loan_id' => $loan->id,
            'type' => LoanActivityType::StatusChange,
            'from_status' => $from,
            'to_status' => $to,
            'remarks' => $remarks,
            'metadata' => $metadata ?: null,
            'performed_by' => $by?->id,
            'performed_at' => now(),
        ]);
    }

    /**
     * Rule L-1. The unique index on open_loan_key refuses a second open loan for the same person
     * even when two applications are submitted at the same moment.
     *
     * @param  list<int>  $loanIds
     */
    /**
     * A restored application takes back the open-loan keys it held before it was deleted: every stage from the one
     * the setting loan.open_loan_scope names (a draft never holds one, a rejected or cancelled application released it).
     *
     * @param  iterable<Loan>  $loans
     */
    public function reclaimOpenLoanKeys(iterable $loans): void
    {
        // A written-off loan keeps its borrowers' key until the debt is recovered (their parties are released then).
        $holding = $this->openFrom() === LoanStatus::Approved
            ? [LoanStatus::Approved, LoanStatus::Accepted, LoanStatus::Active, LoanStatus::WrittenOff]
            : [LoanStatus::Submitted, LoanStatus::InReview, LoanStatus::Returned, LoanStatus::Verified, LoanStatus::Approved, LoanStatus::Accepted, LoanStatus::Active, LoanStatus::WrittenOff];

        $ids = collect($loans)->filter(fn (Loan $loan) => in_array($loan->status, $holding, true))->pluck('id')->all();

        if ($ids !== []) {
            $this->claimOpenLoanKeys($ids);
        }
    }

    protected function claimOpenLoanKeys(array $loanIds): void
    {
        // A loan given as a special case (more than one open loan) takes no key: the unique index would refuse it.
        $loanIds = Loan::withoutGlobalScope('branch')->whereIn('id', $loanIds)->whereNull('multiple_open_at')->pluck('id')->all();

        if ($loanIds === []) {
            return;
        }

        $parties = LoanParty::with('customer')->whereIn('loan_id', $loanIds)->whereIn('role', PartyRole::borrowingValues())->whereNull('released_at')->get();

        foreach ($parties as $party) {
            try {
                $party->forceFill(['open_loan_key' => $party->customer_id])->save();
            } catch (UniqueConstraintViolationException) {
                throw new BusinessRuleException('BORROWER_HAS_OPEN_LOAN', "{$party->customer->full_name} ({$party->customer->customer_no}) already has an open loan.", 409, [
                    'customer_no' => $party->customer->customer_no,
                ]);
            }
        }
    }

    /**
     * Rule G-6: a closed loan frees its borrowers, releases its guarantors and hands back collateral.
     *
     * @param  list<int>  $loanIds
     */
    /**
     * A written-off loan is closed in the books only: the collateral, the guarantees, the
     * borrower's open-loan key and the recovery case all stay until the debt is recovered.
     */
    protected function releasesOnClose(LoanStatus $to): bool
    {
        return $to->isClosed() && $to !== LoanStatus::WrittenOff;
    }

    /**
     * A written-off loan whose debt has been recovered in full lets go of its securities.
     */
    public function releaseRecovered(Loan $loan, User $by): void
    {
        $this->releaseSecurities([$loan->id], $by);
        $this->record($loan, LoanActivityType::Update, $by, 'Written-off debt recovered in full; securities released');
    }

    /**
     * @param  list<int>  $loanIds
     */
    protected function voidDecisions(array $loanIds): void
    {
        Loan::withoutGlobalScope('branch')->whereIn('id', $loanIds)->update([
            'approved_amount' => null, 'approved_at' => null, 'accepted_at' => null, 'offer_on_hold_at' => null, 'offer_remarks' => null,
        ]);
        LoanParty::whereIn('loan_id', $loanIds)->update(['consented_at' => null, 'consent_hash' => null]);
    }

    protected function releaseSecurities(array $loanIds, ?User $by): void
    {
        LoanParty::whereIn('loan_id', $loanIds)->whereNull('released_at')->update([
            'open_loan_key' => null,
            'released_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('collaterals')
            ->whereIn('loan_id', $loanIds)
            ->whereIn('status', [CollateralStatus::Proposed->value, CollateralStatus::Verified->value, CollateralStatus::Perfected->value])
            ->update([
                'status' => CollateralStatus::Released->value,
                'released_by' => $by?->id,
                'released_at' => now(),
                'updated_at' => now(),
            ]);

        // A closed loan closes its recovery case.
        DB::table('recovery_cases')
            ->whereIn('loan_id', $loanIds)
            ->where('status', RecoveryStatus::Open->value)
            ->update([
                'status' => RecoveryStatus::Closed->value,
                'open_case_key' => null,
                'closed_at' => now(),
                'outcome' => 'Loan closed',
                'updated_at' => now(),
            ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The life of a loan, from application to closure (design section 5.1).
 *
 * Delinquency is NOT a status: an active loan stays active and carries days_past_due,
 * arrears and risk stage instead (the old system flapped between active and overdue).
 *
 * The allowed moves are declared once here; LoanWorkflowService is the only code that
 * changes a loan's status and it refuses anything not listed.
 */
enum LoanStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case Returned = 'returned';
    case Verified = 'verified';
    case Approved = 'approved';
    case Accepted = 'accepted';
    case Active = 'active';
    case Settled = 'settled';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case WrittenOff = 'written_off';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Cancelled],
            // Returned is reachable from every stage before payout: an edit to the money terms, or a reviewer, sends the
            // application back to be reviewed again.
            self::Submitted => [self::InReview, self::Returned, self::Cancelled],
            self::InReview => [self::Returned, self::Verified, self::Rejected, self::Cancelled],
            self::Returned => [self::Submitted, self::Cancelled],
            self::Verified => [self::Approved, self::Returned, self::Rejected, self::Cancelled],
            self::Approved => [self::Accepted, self::Returned, self::Cancelled],
            self::Accepted => [self::Active, self::Returned, self::Cancelled],
            self::Active => [self::Settled, self::WrittenOff],
            self::Settled, self::Rejected, self::Cancelled, self::WrittenOff => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Closed loans are final: rejected is never reopened (a new application is made instead),
     * so the audit trail of the old one stays intact.
     */
    public function isClosed(): bool
    {
        return in_array($this, self::closed(), true);
    }

    /**
     * A loan can be edited at every stage (who may is decided by the permission). Once it is paid out only its details change
     * (purpose, custom fields, application date): the money terms change through the servicing actions (restructure, settlement).
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::InReview, self::Returned, self::Verified, self::Approved, self::Accepted, self::Active, self::Settled, self::WrittenOff], true);
    }

    /**
     * The money has gone out: the loan is in the books (schedule, payments, accounting entries).
     */
    public function isPaidOut(): bool
    {
        return in_array($this, [self::Active, self::Settled, self::WrittenOff], true);
    }

    /**
     * Before submission (draft) and while returned for correction, nobody has decided on the figures yet, so they
     * change freely. At any later stage a change to the money terms needs the application to be reviewed again.
     */
    public function changesNeedNoReview(): bool
    {
        return in_array($this, [self::Draft, self::Returned], true);
    }

    /**
     * Group rule GR-1: from approval until closure the group's membership is frozen.
     *
     * @return list<self>
     */
    public static function groupFreezing(): array
    {
        return [self::Approved, self::Accepted, self::Active];
    }

    /**
     * @return list<self>
     */
    public static function closed(): array
    {
        return [self::Settled, self::Rejected, self::Cancelled, self::WrittenOff];
    }

    /**
     * @return list<string>
     */
    public static function closedValues(): array
    {
        return array_map(fn (self $status) => $status->value, self::closed());
    }
}

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
            self::Submitted => [self::InReview, self::Cancelled],
            self::InReview => [self::Returned, self::Verified, self::Rejected, self::Cancelled],
            self::Returned => [self::Submitted, self::Cancelled],
            self::Verified => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Accepted, self::Cancelled],
            self::Accepted => [self::Active, self::Cancelled],
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
     * Applications can be edited only before they are submitted, or after being returned.
     */
    public function isEditable(): bool
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

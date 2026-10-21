<?php

namespace App\Models;

use App\Enums\AllocationComponent;
use App\Enums\InstallmentStatus;
use App\Finance\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class LoanInstallment extends Model
{
    /**
     * Paid column per component. Penalty also has penalty_waived, which reduces what is owed
     * without being a payment.
     */
    protected const PAID_COLUMNS = [
        'principal' => 'principal_paid',
        'profit' => 'profit_paid',
        'charge' => 'charges_paid',
        'penalty' => 'penalty_paid',
    ];

    /**
     * total_due, total_paid and balance are generated columns and are never written.
     */
    protected $fillable = [
        'loan_id',
        'schedule_version',
        'installment_no',
        'due_date',
        'principal_due',
        'profit_due',
        'charges_due',
        'penalty_due',
        'status',
        'grace_ends_on',
    ];

    protected $casts = [
        'status' => InstallmentStatus::class,
        'due_date' => 'date',
        'grace_ends_on' => 'date',
        'last_penalty_on' => 'date',
        'settled_on' => 'date',
        'principal_due' => 'decimal:2',
        'profit_due' => 'decimal:2',
        'charges_due' => 'decimal:2',
        'penalty_due' => 'decimal:2',
        'principal_paid' => 'decimal:2',
        'profit_paid' => 'decimal:2',
        'charges_paid' => 'decimal:2',
        'penalty_paid' => 'decimal:2',
        'penalty_waived' => 'decimal:2',
        'total_due' => 'decimal:2',
        'total_paid' => 'decimal:2',
        'balance' => 'decimal:2',
        'installment_no' => 'integer',
        'schedule_version' => 'integer',
    ];

    /**
     * Lines of the schedule in force (a restructure marks the replaced lines rescheduled).
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', '!=', InstallmentStatus::Rescheduled->value);
    }

    /**
     * What is still owed on one part. Worked out from the component columns, so it is right even
     * before the generated balance column has been re-read from the database.
     */
    public function unpaid(AllocationComponent $component): BigDecimal
    {
        return match ($component) {
            AllocationComponent::Principal => Money::of($this->principal_due)->minus($this->principal_paid),
            AllocationComponent::Profit => Money::of($this->profit_due)->minus($this->profit_paid),
            AllocationComponent::Charge => Money::of($this->charges_due)->minus($this->charges_paid),
            AllocationComponent::Penalty => Money::of($this->penalty_due)->minus($this->penalty_paid)->minus($this->penalty_waived),
            AllocationComponent::Advance => Money::zero(),
        };
    }

    public function unpaidTotal(): BigDecimal
    {
        return Money::sum(array_map(fn (AllocationComponent $c) => $this->unpaid($c), [
            AllocationComponent::Principal, AllocationComponent::Profit, AllocationComponent::Charge, AllocationComponent::Penalty,
        ]));
    }

    /**
     * Unpaid installment amount without late charges: the base a penalty is worked out on,
     * so a penalty is never charged on a penalty.
     */
    public function unpaidExcludingPenalty(): BigDecimal
    {
        return $this->unpaid(AllocationComponent::Principal)
            ->plus($this->unpaid(AllocationComponent::Profit))
            ->plus($this->unpaid(AllocationComponent::Charge));
    }

    /**
     * Add a payment (or, with a negative amount, a reversal) to one part. Never more than owed,
     * never below zero.
     */
    public function addPaid(AllocationComponent $component, BigDecimal $amount): void
    {
        $column = self::PAID_COLUMNS[$component->value] ?? throw new InvalidArgumentException("{$component->value} is not paid on an installment.");
        $paid = Money::of($this->{$column})->plus($amount);

        if ($paid->isNegative() || $amount->isGreaterThan($this->unpaid($component))) {
            throw new InvalidArgumentException("The {$component->value} paid on installment {$this->installment_no} would be out of range.");
        }

        $this->{$column} = (string) $paid;
    }

    /**
     * The status as of a business date.
     */
    public function syncStatus(CarbonInterface $date): void
    {
        if ($this->status === InstallmentStatus::Rescheduled) {
            return;
        }

        if ($this->unpaidTotal()->isZero()) {
            $this->status = InstallmentStatus::Paid;
            $this->settled_on ??= $date->toDateString();

            return;
        }

        $this->settled_on = null;
        $anyPaid = Money::of($this->principal_paid)->plus($this->profit_paid)->plus($this->charges_paid)->plus($this->penalty_paid)->isPositive();

        $this->status = match (true) {
            $date->greaterThan($this->grace_ends_on) => InstallmentStatus::Overdue,
            $anyPaid => InstallmentStatus::PartiallyPaid,
            $date->greaterThanOrEqualTo($this->due_date) => InstallmentStatus::Due,
            default => InstallmentStatus::Upcoming,
        };
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LoanAllocation::class);
    }
}

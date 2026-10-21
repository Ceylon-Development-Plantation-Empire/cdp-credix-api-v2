<?php

namespace App\Enums;

/**
 * Every fee and charge a product can carry. Each type knows which calculations,
 * collection methods and recurrences make sense for it, so a product cannot be set up
 * with, for example, a processing fee that is a % of the overdue amount.
 */
enum ChargeType: string
{
    case ProcessingFee = 'processing_fee';
    case DocumentationFee = 'documentation_fee';
    case Insurance = 'insurance';
    case StampDuty = 'stamp_duty';
    case ValuationFee = 'valuation_fee';
    case LatePayment = 'late_payment';
    case EarlySettlement = 'early_settlement';
    case SafekeepingFee = 'safekeeping_fee';

    /**
     * @return list<ChargeCalculation>
     */
    public function allowedCalculations(): array
    {
        return match ($this) {
            self::LatePayment => [ChargeCalculation::Fixed, ChargeCalculation::PercentOfOverdue],
            self::EarlySettlement => [ChargeCalculation::Fixed, ChargeCalculation::PercentOfOutstanding],
            self::SafekeepingFee => [ChargeCalculation::Fixed, ChargeCalculation::PerGramPerMonth],
            default => [ChargeCalculation::Fixed, ChargeCalculation::PercentOfPrincipal],
        };
    }

    /**
     * @return list<ChargeCollection>
     */
    public function allowedCollections(): array
    {
        return $this->isEventCharge()
            ? [ChargeCollection::OnEvent]
            : [ChargeCollection::DeductFromDisbursement, ChargeCollection::AddToFirstInstallment];
    }

    /**
     * @return list<ChargeRecurrence>
     */
    public function allowedRecurrences(): array
    {
        return match ($this) {
            self::LatePayment => [ChargeRecurrence::Once, ChargeRecurrence::MonthlyWhileOverdue],
            self::Insurance => [ChargeRecurrence::Once, ChargeRecurrence::PerInstallment],
            default => [ChargeRecurrence::Once],
        };
    }

    /**
     * Charges raised by something that happens later (a late payment, an early settlement, storing gold)
     * rather than at disbursement.
     */
    public function isEventCharge(): bool
    {
        return in_array($this, [self::LatePayment, self::EarlySettlement, self::SafekeepingFee], true);
    }
}

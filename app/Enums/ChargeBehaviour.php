<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the servicing engine does with a charge type (charge_types.behaviour).
 *
 * The fee types themselves are business data (App\Models\ChargeType). Only these behaviours are
 * code, because each one is calculated by a different part of the engine. Upfront is the open
 * behaviour: any number of upfront fee types (processing, documentation, legal …) can be added as
 * data. Each engine-raised behaviour exists exactly once, as a seeded system charge type.
 */
enum ChargeBehaviour: string
{
    // Taken at disbursement or added to the first installment (processing fee, stamp duty, insurance …).
    case Upfront = 'upfront';
    // Raised by the nightly run when an installment is past its grace period (PenaltyService).
    case LatePayment = 'late_payment';
    // Raised when the loan is settled early (EarlySettlementService).
    case EarlySettlement = 'early_settlement';
    // Gold / Rahn: rupees per gram per month while the gold is held (SafekeepingFeeService).
    case Safekeeping = 'safekeeping';

    /**
     * Raised by something that happens later (a late payment, an early payoff, storing gold),
     * rather than at disbursement.
     */
    public function isEventDriven(): bool
    {
        return $this !== self::Upfront;
    }

    /**
     * Engine behaviours exist once each, as seeded system charge types.
     */
    public function isSystemOnly(): bool
    {
        return $this->isEventDriven();
    }

    /**
     * How a charge of this behaviour can be collected.
     *
     * @return list<ChargeCollection>
     */
    public function allowedCollections(): array
    {
        return $this->isEventDriven()
            ? [ChargeCollection::OnEvent]
            : [ChargeCollection::DeductFromDisbursement, ChargeCollection::AddToFirstInstallment];
    }

    /**
     * Calculations that make sense for the behaviour. A charge type may narrow this list further.
     *
     * @return list<ChargeCalculation>
     */
    public function permittedCalculations(): array
    {
        return match ($this) {
            self::Upfront => [ChargeCalculation::Fixed, ChargeCalculation::PercentOfPrincipal],
            self::LatePayment => [ChargeCalculation::Fixed, ChargeCalculation::PercentOfOverdue],
            self::EarlySettlement => [ChargeCalculation::Fixed, ChargeCalculation::PercentOfOutstanding],
            self::Safekeeping => [ChargeCalculation::Fixed, ChargeCalculation::PerGramPerMonth],
        };
    }

    /**
     * Recurrences that make sense for the behaviour. A charge type may narrow this list further.
     *
     * @return list<ChargeRecurrence>
     */
    public function permittedRecurrences(): array
    {
        return match ($this) {
            self::Upfront => [ChargeRecurrence::Once, ChargeRecurrence::PerInstallment],
            self::LatePayment => [ChargeRecurrence::Once, ChargeRecurrence::MonthlyWhileOverdue],
            self::EarlySettlement, self::Safekeeping => [ChargeRecurrence::Once],
        };
    }
}

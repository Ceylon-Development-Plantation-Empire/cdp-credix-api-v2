<?php

declare(strict_types=1);

namespace App\Http\Resources\Loans;

use App\Models\LoanInstallment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanInstallment
 */
class LoanInstallmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'installment_no' => $this->installment_no,
            'schedule_version' => $this->schedule_version,
            'due_date' => $this->due_date->toDateString(),
            'grace_ends_on' => $this->grace_ends_on->toDateString(),
            'principal_due' => (string) $this->principal_due,
            'profit_due' => (string) $this->profit_due,
            'charges_due' => (string) $this->charges_due,
            'penalty_due' => (string) $this->penalty_due,
            'total_due' => (string) $this->total_due,
            'total_paid' => (string) $this->total_paid,
            'balance' => (string) $this->balance,
            'status' => $this->status->value,
            'settled_on' => $this->settled_on?->toDateString(),
        ];
    }
}

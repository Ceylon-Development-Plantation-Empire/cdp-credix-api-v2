<?php

namespace App\Http\Resources\Loans;

use App\Models\LoanTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ledger entry. The idempotency key and internal ids are never returned.
 *
 * @mixin LoanTransaction
 */
class LoanTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'txn_no' => $this->txn_no,
            'loan' => $this->whenLoaded('loan', fn () => [
                'public_id' => $this->loan->public_id,
                'application_no' => $this->loan->application_no,
                'loan_no' => $this->loan->loan_no,
            ]),
            'type' => $this->type->value,
            'amount' => (string) $this->amount,
            'value_date' => $this->value_date->toDateString(),
            'channel' => $this->channel->value,
            'external_reference' => $this->external_reference,
            'payer' => $this->whenLoaded('payer', fn () => $this->payer ? ['public_id' => $this->payer->public_id, 'full_name' => $this->payer->full_name] : null),
            'narration' => $this->narration,
            'is_charity' => $this->is_charity,
            'reversed' => $this->whenLoaded('reversal', fn () => $this->reversal !== null),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'installment_no' => $a->installment?->installment_no,
                'component' => $a->component->value,
                'amount' => (string) $a->amount,
            ])->values()),
            'posted_by' => $this->whenLoaded('poster', fn () => $this->poster ? ['name' => $this->poster->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

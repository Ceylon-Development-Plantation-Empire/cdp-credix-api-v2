<?php

namespace App\Http\Resources\Accounting;

use App\Models\GlJournal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GlJournal
 */
class GlJournalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'journal_no' => $this->journal_no,
            'source' => $this->source->value,
            'status' => $this->status->value,
            'entry_date' => $this->entry_date->toDateString(),
            'value_date' => $this->value_date->toDateString(),
            'narration' => $this->narration,
            'total' => (string) $this->total,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? ['id' => $this->branch->id, 'name' => $this->branch->name] : null),
            'loan' => $this->whenLoaded('loan', fn () => $this->loan ? ['public_id' => $this->loan->public_id, 'loan_no' => $this->loan->loan_no] : null),
            'transaction' => $this->whenLoaded('transaction', fn () => $this->transaction ? ['public_id' => $this->transaction->public_id, 'txn_no' => $this->transaction->txn_no] : null),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? ['name' => $this->creator->name] : null),
            'approved_by' => $this->whenLoaded('approver', fn () => $this->approver ? ['name' => $this->approver->name] : null),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'account' => ['id' => $line->account->id, 'code' => $line->account->code, 'name' => $line->account->name],
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
                'memo' => $line->memo,
            ])),
        ];
    }
}

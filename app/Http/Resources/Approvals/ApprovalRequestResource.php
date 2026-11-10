<?php

declare(strict_types=1);

namespace App\Http\Resources\Approvals;

use App\Models\ApprovalRequest;
use App\Models\GlJournal;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalRequest
 */
class ApprovalRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'action' => $this->action->value,
            'subject' => $this->subjectSummary(),
            'reason' => $this->reason,
            'status' => $this->status->value,
            'requested_by' => $this->whenLoaded('requester', fn () => ['name' => $this->requester->name]),
            'requested_at' => $this->created_at?->toApiString(),
            'decided_by' => $this->whenLoaded('decider', fn () => $this->decider ? ['name' => $this->decider->name] : null),
            'decided_at' => $this->decided_at?->toApiString(),
            'decision_reference' => $this->decision_reference,
            'decision_remarks' => $this->decision_remarks,
            // The proposed change is shown on the detail screen only; the list stays light.
            'payload' => $this->when($request->routeIs('approval-requests.show'), $this->payload),
        ];
    }

    /**
     * A readable pointer to what the request is about, never the internal id.
     *
     * @return array<string, string>|null
     */
    protected function subjectSummary(): ?array
    {
        $subject = $this->subject;

        return match (true) {
            $subject instanceof Product => ['type' => 'product', 'slug' => $subject->slug, 'name' => $subject->name],
            $subject instanceof Loan => ['type' => 'loan', 'public_id' => $subject->public_id, 'application_no' => $subject->application_no, 'loan_no' => $subject->loan_no],
            $subject instanceof LoanTransaction => ['type' => 'payment', 'public_id' => $subject->public_id, 'txn_no' => $subject->txn_no, 'amount' => (string) $subject->amount],
            $subject instanceof GlJournal => ['type' => 'journal', 'public_id' => $subject->public_id, 'journal_no' => $subject->journal_no, 'amount' => (string) $subject->total],
            default => null,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Legal;

use App\Models\LegalDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A legal document with the loan it is for. The wording (template content) is sent only when asked
 * for one document (show), not in lists.
 *
 * @mixin LegalDocument
 */
class LegalDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'reference_no' => $this->reference_no,
            'loan' => $this->whenLoaded('loan', fn () => [
                'public_id' => $this->loan->public_id,
                'application_no' => $this->loan->application_no,
                'loan_no' => $this->loan->loan_no,
                'status' => $this->loan->status->value,
                'customer' => $this->loan->customer ? [
                    'public_id' => $this->loan->customer->public_id,
                    'customer_no' => $this->loan->customer->customer_no,
                    'full_name' => $this->loan->customer->full_name,
                ] : null,
                'borrower_group' => $this->loan->borrowerGroup ? ['public_id' => $this->loan->borrowerGroup->public_id, 'name' => $this->loan->borrowerGroup->name] : null,
                'product' => ['slug' => $this->loan->product->slug, 'name' => $this->loan->product->name],
                'branch' => ['id' => $this->loan->branch->id, 'name' => $this->loan->branch->name],
            ]),
            'document_type' => $this->document_type->value,
            'document_type_label' => $this->document_type->label(),
            'language' => $this->language->value,
            'status' => $this->status,
            'template' => $this->whenLoaded('template', fn () => $this->template ? array_filter([
                'id' => $this->template->id,
                'content' => $request->routeIs('legal-documents.show') ? $this->template->body : null,
            ], fn ($v) => $v !== null) : null),
            'details' => $this->details ?? (object) [],
            'remarks' => $this->remarks,
            'printed_count' => $this->printed_count,
            'last_printed_at' => $this->last_printed_at?->toApiString(),
            'prepared_at' => $this->prepared_at?->toApiString(),
            'created_at' => $this->created_at?->toApiString(),
            'updated_at' => $this->updated_at?->toApiString(),
        ];
    }
}

<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Enums\PartyRole;
use App\Enums\RequirementRule;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Works out which documents a person still needs, from the rules in document_requirements.
 *
 * Rules are grouped by requirement_group:
 * - "any" group: satisfied when at least one of its document types is satisfied
 *   (identity: NIC or passport or driving licence);
 * - "all" group: satisfied when every one of its document types is satisfied
 *   (residency: GN letter and DS letter).
 * A document type is satisfied when at least min_count VERIFIED documents of that type are
 * still current (not expired, issued within the type's validity period). Uploaded but not yet
 * verified documents are reported separately so staff can see what is waiting for review.
 *
 * Product-specific rules replace the global rules of the same group, so a product can ask for
 * different employment proof without touching the defaults.
 */
class DocumentChecklistService
{
    /**
     * @return array{complete: bool, groups: list<array<string, mixed>>}
     */
    public function forCustomer(Customer $customer, PartyRole $role, ?Product $product = null): array
    {
        $requirements = $this->requirementsFor($role, $customer->employment_status?->value, $product);
        $documents = $customer->documents()->where('status', '!=', DocumentStatus::Rejected->value)->get()->groupBy('document_type_id');

        $groups = $requirements->groupBy('requirement_group')->map(function (Collection $rows, string $group) use ($documents) {
            $items = $rows->map(fn (DocumentRequirement $row) => $this->evaluate($row, $documents->get($row->document_type_id, collect())));
            $rule = $rows->first()->rule;

            $satisfied = $rule === RequirementRule::Any
                ? $items->contains('satisfied', true)
                : $items->every(fn ($item) => $item['satisfied']);

            return [
                'requirement_group' => $group,
                'rule' => $rule->value,
                'is_mandatory' => $rows->contains('is_mandatory', true),
                'satisfied' => $satisfied,
                'documents' => $items->values()->all(),
            ];
        })->values();

        return [
            'complete' => $groups->every(fn ($group) => ! $group['is_mandatory'] || $group['satisfied']),
            'groups' => $groups->all(),
        ];
    }

    /**
     * @return Collection<int, DocumentRequirement>
     */
    protected function requirementsFor(PartyRole $role, ?string $employmentStatus, ?Product $product): Collection
    {
        $rows = DocumentRequirement::with('documentType')
            ->where('party_role', $role->value)
            ->where(fn ($q) => $q->whereNull('employment_status')->orWhere('employment_status', $employmentStatus))
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $product?->id ?? 0))
            ->orderBy('id')
            ->get();

        // Groups the product defines itself win over the global group with the same name.
        $productGroups = $rows->whereNotNull('product_id')->pluck('requirement_group')->unique();

        return $rows->reject(fn (DocumentRequirement $row) => $row->product_id === null && $productGroups->contains($row->requirement_group))->values();
    }

    /**
     * @param  Collection<int, Document>  $documents  This person's documents of the row's type.
     * @return array<string, mixed>
     */
    protected function evaluate(DocumentRequirement $row, Collection $documents): array
    {
        $validityDays = $row->documentType->validity_days;
        $current = $documents->filter(fn (Document $document) => $document->isCurrent($validityDays));
        $verified = $current->where('status', DocumentStatus::Verified)->count();
        $pending = $current->where('status', DocumentStatus::Pending)->count();

        return [
            'document_type' => ['slug' => $row->documentType->slug, 'name' => $row->documentType->name],
            'min_count' => $row->min_count,
            'verified_count' => $verified,
            'pending_count' => $pending,
            'satisfied' => $verified >= $row->min_count,
        ];
    }
}

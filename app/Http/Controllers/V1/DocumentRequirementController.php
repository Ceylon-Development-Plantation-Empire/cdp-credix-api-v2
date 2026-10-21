<?php

namespace App\Http\Controllers\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\DocumentRequirementRequest;
use App\Http\Resources\Documents\DocumentRequirementResource;
use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Models\Product;
use App\Traits\ActivityLogTrait;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class DocumentRequirementController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, HandlesApiErrors;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Document Requirement Index', only: ['index']),
            new Middleware('permission:Document Requirement Create', only: ['store']),
            new Middleware('permission:Document Requirement Update', only: ['update']),
            new Middleware('permission:Document Requirement Delete', only: ['destroy']),
        ];
    }

    /**
     * ?product_slug=x returns the global rules plus the rules specific to that product.
     */
    public function index(Request $request)
    {
        try {
            $query = DocumentRequirement::with(['product', 'documentType'])
                ->orderBy('party_role')->orderBy('requirement_group')->orderBy('id');

            if ($request->filled('product_slug')) {
                $product = Product::findBySlug($request->product_slug);
                $query->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $product?->id ?? 0));
            } elseif ($request->boolean('global_only')) {
                $query->whereNull('product_id');
            }

            if ($request->filled('party_role')) {
                $query->where('party_role', $request->party_role);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Document requirements retrieved successfully',
                'data' => DocumentRequirementResource::collection($query->get()),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve document requirements', $th);
        }
    }

    public function store(DocumentRequirementRequest $request)
    {
        try {
            $attributes = $this->attributes($request->validated());
            $this->assertNotDuplicate($attributes);

            $requirement = DocumentRequirement::create($attributes);

            $this->logActivity('CREATE', 'DocumentRequirement', "Created document requirement #{$requirement->id}", $attributes);

            return response()->json([
                'status' => 'success',
                'message' => 'Document requirement created successfully',
                'data' => new DocumentRequirementResource($requirement->load(['product', 'documentType'])),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to create document requirement', $th);
        }
    }

    public function update(DocumentRequirementRequest $request, string $id)
    {
        try {
            $requirement = DocumentRequirement::find($id);

            if (! $requirement) {
                return $this->notFound('Document requirement not found');
            }

            $attributes = $this->attributes($request->validated());
            $this->assertNotDuplicate($attributes, $requirement->id);

            $requirement->update($attributes);

            $this->logActivity('UPDATE', 'DocumentRequirement', "Updated document requirement #{$requirement->id}", $attributes);

            return response()->json([
                'status' => 'success',
                'message' => 'Document requirement updated successfully',
                'data' => new DocumentRequirementResource($requirement->load(['product', 'documentType'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update document requirement', $th);
        }
    }

    public function destroy(string $id)
    {
        try {
            $requirement = DocumentRequirement::find($id);

            if (! $requirement) {
                return $this->notFound('Document requirement not found');
            }

            $requirement->delete();

            $this->logActivity('DELETE', 'DocumentRequirement', "Deleted document requirement #{$id}", $requirement->toArray());

            return response()->json([
                'status' => 'success',
                'message' => 'Document requirement deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to delete document requirement', $th);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function attributes(array $data): array
    {
        return [
            'product_id' => isset($data['product_slug']) ? Product::findBySlug($data['product_slug'])->id : null,
            'party_role' => $data['party_role'],
            'employment_status' => $data['employment_status'] ?? null,
            'requirement_group' => $data['requirement_group'],
            'rule' => $data['rule'],
            'document_type_id' => DocumentType::findBySlug($data['document_type_slug'])->id,
            'min_count' => $data['min_count'] ?? 1,
            'is_mandatory' => $data['is_mandatory'] ?? true,
        ];
    }

    /**
     * The unique index cannot catch duplicates where product or employment status is null
     * (MySQL treats every NULL as different), so the check is done here as well.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function assertNotDuplicate(array $attributes, ?int $ignoreId = null): void
    {
        $exists = DocumentRequirement::query()
            ->where('party_role', $attributes['party_role'])
            ->where('requirement_group', $attributes['requirement_group'])
            ->where('document_type_id', $attributes['document_type_id'])
            ->when($attributes['product_id'], fn ($q, $id) => $q->where('product_id', $id), fn ($q) => $q->whereNull('product_id'))
            ->when($attributes['employment_status'], fn ($q, $status) => $q->where('employment_status', $status), fn ($q) => $q->whereNull('employment_status'))
            ->when($ignoreId, fn ($q, $id) => $q->whereKeyNot($id))
            ->exists();

        if ($exists) {
            throw new BusinessRuleException('DOCUMENT_REQUIREMENT_EXISTS', 'This document is already part of that requirement.', 422);
        }

        // Every row of one requirement group must use the same rule, otherwise "any" and "all" conflict.
        $conflict = DocumentRequirement::query()
            ->where('party_role', $attributes['party_role'])
            ->where('requirement_group', $attributes['requirement_group'])
            ->where('rule', '!=', $attributes['rule'])
            ->when($attributes['product_id'], fn ($q, $id) => $q->where('product_id', $id), fn ($q) => $q->whereNull('product_id'))
            ->when($attributes['employment_status'], fn ($q, $status) => $q->where('employment_status', $status), fn ($q) => $q->whereNull('employment_status'))
            ->when($ignoreId, fn ($q, $id) => $q->whereKeyNot($id))
            ->exists();

        if ($conflict) {
            throw new BusinessRuleException('DOCUMENT_REQUIREMENT_RULE_CONFLICT', 'All documents in one requirement group must use the same rule (any or all).', 422);
        }
    }
}

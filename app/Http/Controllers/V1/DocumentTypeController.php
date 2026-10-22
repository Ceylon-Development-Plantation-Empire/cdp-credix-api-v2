<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\CreateDocumentTypeRequest;
use App\Http\Requests\Documents\UpdateDocumentTypeRequest;
use App\Http\Resources\Documents\DocumentTypeResource;
use App\Models\DocumentType;
use App\Traits\ActivityLogTrait;
use App\Traits\DeletesRecords;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class DocumentTypeController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, DeletesRecords, HandlesApiErrors;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Document Type Delete', only: ['destroy']),
            new Middleware('permission:Document Type Index', only: ['index', 'show']),
            new Middleware('permission:Document Type Create', only: ['store']),
            new Middleware('permission:Document Type Update', only: ['update']),
            new Middleware('permission:Document Type Toggle Status', only: ['toggleStatus']),
        ];
    }

    /**
     * Document types are a short list, so they are returned in full (no pagination).
     */
    public function index(Request $request)
    {
        try {
            $query = DocumentType::query()->orderBy('target')->orderBy('name');

            if ($request->filled('target')) {
                $query->where('target', $request->target);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Document types retrieved successfully',
                'data' => DocumentTypeResource::collection($query->get()),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve document types', $th);
        }
    }

    public function store(CreateDocumentTypeRequest $request)
    {
        try {
            $data = $this->withMimeList($request->validated());
            $type = DocumentType::create($data);

            $this->logActivity('CREATE', 'DocumentType', "Created document type: {$type->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Document type created successfully',
                'data' => new DocumentTypeResource($type),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to create document type', $th);
        }
    }

    public function show(string $slug)
    {
        try {
            $type = DocumentType::findBySlug($slug);

            if (! $type) {
                return $this->notFound('Document type not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Document type retrieved successfully',
                'data' => new DocumentTypeResource($type),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve document type', $th);
        }
    }

    public function update(UpdateDocumentTypeRequest $request, string $slug)
    {
        try {
            $type = DocumentType::findBySlug($slug);

            if (! $type) {
                return $this->notFound('Document type not found');
            }

            $data = $this->withMimeList($request->validated());
            $type->update($data);

            $this->logActivity('UPDATE', 'DocumentType', "Updated document type: {$type->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Document type updated successfully',
                'data' => new DocumentTypeResource($type),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update document type', $th);
        }
    }

    public function toggleStatus(string $slug)
    {
        try {
            $type = DocumentType::findBySlug($slug);

            if (! $type) {
                return $this->notFound('Document type not found');
            }

            $type->update(['is_active' => ! $type->is_active]);

            $this->logActivity('TOGGLE_STATUS', 'DocumentType', "Toggled document type status: {$type->name} (".($type->is_active ? 'Active' : 'Inactive').')');

            return response()->json([
                'status' => 'success',
                'message' => 'Document type status updated successfully',
                'data' => [
                    'slug' => $type->slug,
                    'is_active' => $type->is_active,
                ],
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to toggle document type status', $th);
        }
    }

    /**
     * The request takes the allowed formats as a list; the column stores them comma separated.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withMimeList(array $data): array
    {
        if (isset($data['allowed_mimes'])) {
            $data['allowed_mimes'] = implode(',', $data['allowed_mimes']);
        }

        return $data;
    }

    public function destroy(string $slug)
    {
        try {
            return $this->destroyRecord(DocumentType::findBySlug($slug), 'document type', 'DocumentType', fn (DocumentType $type) => match (true) {
                $type->requirements()->exists() => 'Document requirements use this type. Remove them or deactivate the type instead.',
                \App\Models\Document::withTrashed()->where('document_type_id', $type->id)->exists() => 'Documents were uploaded with this type. Deactivate it instead.',
                default => null,
            });
        } catch (\Throwable $th) {
            return $this->serverError('Failed to delete document type', $th);
        }
    }
}

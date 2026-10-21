<?php

namespace App\Http\Controllers\V1;

use App\Enums\PartyRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\UploadDocumentRequest;
use App\Http\Resources\Documents\DocumentResource;
use App\Models\Customer;
use App\Models\DocumentType;
use App\Models\Product;
use App\Services\Documents\DocumentChecklistService;
use App\Services\Documents\DocumentService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class CustomerDocumentController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    public function __construct(
        protected DocumentService $documents,
        protected DocumentChecklistService $checklist,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Document Index', only: ['index', 'checklist']),
            new Middleware('permission:Document Upload', only: ['store']),
        ];
    }

    public function index(Request $request, string $publicId)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            $documents = $customer->documents()->with(['documentType', 'uploader', 'verifier'])
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
                ->latest('id')->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Customer documents retrieved successfully',
                'data' => DocumentResource::collection($documents),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve customer documents', $th);
        }
    }

    public function store(UploadDocumentRequest $request, string $publicId)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            $data = $request->validated();
            $document = $this->documents->upload(
                $customer,
                DocumentType::findBySlug($data['document_type_slug']),
                $request->file('file'),
                $data,
                $request->user(),
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Document uploaded successfully',
                'data' => new DocumentResource($document->load(['documentType', 'uploader'])),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to upload document', $th);
        }
    }

    /**
     * Which documents this person still needs for a role (borrower, guarantor …),
     * optionally for a specific product. ?role=guarantor&product_slug=sme-loan
     */
    public function checklist(Request $request, string $publicId)
    {
        try {
            $data = $request->validate([
                'role' => ['sometimes', Rule::enum(PartyRole::class)],
                'product_slug' => ['nullable', 'string', Rule::exists('products', 'slug')],
            ]);

            $customer = Customer::where('public_id', $publicId)->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            $role = PartyRole::from($data['role'] ?? PartyRole::Borrower->value);
            $product = isset($data['product_slug']) ? Product::findBySlug($data['product_slug']) : null;

            return response()->json([
                'status' => 'success',
                'message' => 'Document checklist retrieved successfully',
                'data' => ['role' => $role->value] + $this->checklist->forCustomer($customer, $role, $product),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to build document checklist', $th);
        }
    }
}

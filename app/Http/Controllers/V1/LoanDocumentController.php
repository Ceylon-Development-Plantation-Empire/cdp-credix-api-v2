<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\UploadDocumentRequest;
use App\Http\Resources\Documents\DocumentResource;
use App\Models\DocumentType;
use App\Models\Loan;
use App\Services\Documents\DocumentService;
use App\Traits\HandlesApiErrors;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Documents of the loan itself (the signed agreement). People's documents are uploaded on the
 * customer, collateral documents on the collateral.
 */
class LoanDocumentController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    public function __construct(
        protected DocumentService $documents,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Document Index', only: ['index']),
            new Middleware('permission:Document Upload', only: ['store']),
        ];
    }

    public function index(string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Loan documents retrieved successfully',
                'data' => DocumentResource::collection($loan->documents()->with(['documentType', 'uploader', 'verifier'])->latest('id')->get()),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve loan documents', $th);
        }
    }

    public function store(UploadDocumentRequest $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $data = $request->validated();
            $document = $this->documents->upload($loan, DocumentType::findBySlug($data['document_type_slug']), $request->file('file'), $data, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Document uploaded successfully',
                'data' => new DocumentResource($document->load(['documentType', 'uploader'])),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to upload document', $th);
        }
    }
}

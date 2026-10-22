<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Legal\LegalDocumentRequest;
use App\Http\Resources\Legal\LegalDocumentResource;
use App\Http\Resources\Loans\LoanListResource;
use App\Models\LegalDocument;
use App\Models\Loan;
use App\Services\Legal\LegalDocumentService;
use App\Traits\ActivityLogTrait;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Legal documents of loans: the loans waiting for their papers, the prepared documents, printing.
 * Documents are addressed by public_id; a user who cannot see a loan cannot see its documents.
 */
class LegalDocumentController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, HandlesApiErrors, PaginatesResources;

    private const RELATIONS = ['loan.customer', 'loan.borrowerGroup', 'loan.product', 'loan.branch', 'template'];

    public function __construct(
        protected LegalDocumentService $legal,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Legal Document Index', only: ['index', 'applications', 'show']),
            new Middleware('permission:Legal Document Manage', only: ['store', 'update', 'destroy', 'recordPrint']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $query = LegalDocument::with(self::RELATIONS)->whereHas('loan')->latest('id');

            foreach (['document_type', 'language', 'status'] as $filter) {
                if ($request->filled($filter)) {
                    $query->where($filter, $request->get($filter));
                }
            }

            if ($request->filled('loan_public_id')) {
                $query->whereHas('loan', fn ($q) => $q->where('public_id', $request->loan_public_id));
            }

            if ($request->filled('search')) {
                $search = (string) $request->search;
                $query->where(fn ($q) => $q->where('reference_no', 'like', "%{$search}%")
                    ->orWhereHas('loan', fn ($l) => $l->search($search)));
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Legal documents retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), LegalDocumentResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve legal documents', $th);
        }
    }

    /**
     * Loans whose papers can be prepared (approved, accepted, active), with or without a document yet.
     */
    public function applications(Request $request)
    {
        try {
            $query = Loan::with(['customer', 'borrowerGroup', 'product', 'branch'])->topLevel()
                ->whereIn('status', array_map(fn ($s) => $s->value, LegalDocumentService::ELIGIBLE))
                ->latest('id');

            match ($request->input('legal_state')) {
                'not_prepared' => $query->whereDoesntHave('legalDocuments'),
                'prepared' => $query->whereHas('legalDocuments'),
                default => null,
            };

            if ($request->filled('search')) {
                $query->search($request->search);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Loans retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), LoanListResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve loans for legal documents', $th);
        }
    }

    public function show(string $publicId)
    {
        return $this->find($publicId, fn (LegalDocument $document) => $this->ok('Legal document retrieved successfully', $document));
    }

    public function store(LegalDocumentRequest $request): JsonResponse
    {
        try {
            $loan = Loan::where('public_id', $request->validated('loan_public_id'))->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $document = $this->legal->create($loan, $request->validated(), $request->user());
            $this->logActivity('CREATE', 'LegalDocument', "Prepared {$document->document_type->value} for {$loan->application_no}", ['status' => $document->status]);

            return $this->ok('Legal document saved successfully', $document, 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to save the legal document', $th);
        }
    }

    public function update(LegalDocumentRequest $request, string $publicId)
    {
        return $this->find($publicId, function (LegalDocument $document) use ($request) {
            $document = $this->legal->update($document, $request->validated(), $request->user());

            return $this->ok('Legal document updated successfully', $document);
        });
    }

    public function destroy(string $publicId)
    {
        return $this->find($publicId, function (LegalDocument $document) {
            $this->legal->delete($document);

            return response()->json(['status' => 'success', 'message' => 'Legal document deleted successfully'], 200);
        });
    }

    public function recordPrint(string $publicId)
    {
        return $this->find($publicId, fn (LegalDocument $document) => $this->ok('Print recorded', $this->legal->recordPrint($document)));
    }

    /**
     * @param  callable(LegalDocument): JsonResponse  $action
     */
    protected function find(string $publicId, callable $action): JsonResponse
    {
        try {
            $document = LegalDocument::with(self::RELATIONS)->whereHas('loan')->where('public_id', $publicId)->first();

            return $document ? $action($document) : $this->notFound('Legal document not found');
        } catch (\Throwable $th) {
            return $this->serverError('Failed to process the legal document', $th);
        }
    }

    protected function ok(string $message, LegalDocument $document, int $status = 200): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => new LegalDocumentResource($document->load(self::RELATIONS)),
        ], $status);
    }
}

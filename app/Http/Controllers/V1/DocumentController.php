<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Enums\LoanStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\DocumentIndexRequest;
use App\Http\Requests\Documents\RejectDocumentRequest;
use App\Http\Requests\Documents\UpdateDocumentRequest;
use App\Http\Resources\Documents\DocumentResource;
use App\Models\Collateral;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Loan;
use App\Models\LoanParty;
use App\Services\Documents\DocumentService;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Actions on a single document, addressed by its public id.
 *
 * A document is only found when its owner (the customer, a loan …) is visible to the user, so
 * the owner's branch scope also protects the file: findAccessible() returns null otherwise.
 */
class DocumentController extends Controller implements HasMiddleware
{
    use HandlesApiErrors, PaginatesResources;

    public function __construct(
        protected DocumentService $documents,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Document Index', only: ['index', 'download']),
            new Middleware('permission:Document Update', only: ['update']),
            new Middleware('permission:Document Verify', only: ['verify', 'reject']),
            new Middleware('permission:Document Delete', only: ['destroy']),
        ];
    }

    /**
     * Every document of the loan applications before approval (submitted, in review, verified unless
     * ?loan_status= says otherwise): the loan's own, its customers' (borrowers and guarantors) and its
     * collateral's. The loan's branch scope applies, and each row says what the user may do with it.
     * Opening one loan's documents (GET /loans/{id}/documents) stays available in every stage.
     */
    public function index(DocumentIndexRequest $request)
    {
        try {
            $stages = $request->loanStatuses() ?? [LoanStatus::Submitted->value, LoanStatus::InReview->value, LoanStatus::Verified->value];

            $loanIds = fn () => Loan::query()
                ->whereIn('loans.status', $stages)
                ->when($request->filled('loan_public_id'), fn (Builder $q) => $q->where('loans.public_id', $request->input('loan_public_id')))
                ->select('loans.id');
            $customerIds = fn () => Customer::query()
                ->where(fn (Builder $q) => $q
                    ->whereIn('customers.id', LoanParty::query()->whereIn('loan_id', $loanIds())->select('customer_id'))
                    ->orWhereIn('customers.id', Loan::query()->whereIn('loans.id', $loanIds())->whereNotNull('customer_id')->select('customer_id')))
                ->select('customers.id');
            $collateralIds = fn () => Collateral::query()->whereIn('loan_id', $loanIds())->select('collaterals.id');

            $owner = $request->input('owner_type');
            $query = Document::query()
                ->where(function (Builder $q) use ($owner, $loanIds, $customerIds, $collateralIds) {
                    if (! $owner || $owner === 'loan') {
                        $q->orWhere(fn (Builder $w) => $w->where('documentable_type', 'loan')->whereIn('documentable_id', $loanIds()));
                    }
                    if (! $owner || $owner === 'customer') {
                        $q->orWhere(fn (Builder $w) => $w->where('documentable_type', 'customer')->whereIn('documentable_id', $customerIds()));
                    }
                    if (! $owner || $owner === 'collateral') {
                        $q->orWhere(fn (Builder $w) => $w->where('documentable_type', 'collateral')->whereIn('documentable_id', $collateralIds()));
                    }
                })
                ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
                ->when($request->filled('document_type_slug'), fn (Builder $q) => $q->whereHas('documentType', fn (Builder $t) => $t->where('slug', $request->input('document_type_slug'))))
                ->when($request->filled('search'), function (Builder $q) use ($request) {
                    $term = '%'.addcslashes((string) $request->input('search'), '%_\\').'%';
                    $q->where(fn (Builder $w) => $w->where('original_name', 'like', $term)
                        ->orWhere('remarks', 'like', $term)
                        ->orWhereHas('documentType', fn (Builder $t) => $t->where('name', 'like', $term)));
                })
                ->with([
                    'documentType', 'uploader', 'verifier',
                    'documentable' => fn ($morph) => $morph->morphWith([Collateral::class => ['loan']]),
                ])
                ->latest('id');

            $page = $query->paginate($this->perPage($request));

            // A customer's document belongs to every loan in scope that person is a party to.
            $customerLoans = LoanParty::query()
                ->whereIn('customer_id', $page->getCollection()->where('documentable_type', 'customer')->pluck('documentable_id')->unique()->all())
                ->whereIn('loan_id', $loanIds())
                ->with('loan')
                ->get()
                ->groupBy('customer_id');

            $user = $request->user();
            $can = [
                'update' => $user->can('Document Update'),
                'verify' => $user->can('Document Verify'),
                'delete' => $user->can('Document Delete'),
            ];
            $loanSummary = fn (Loan $l) => ['public_id' => $l->public_id, 'application_no' => $l->application_no, 'loan_no' => $l->loan_no, 'status' => $l->status->value];

            $items = $page->getCollection()->map(function (Document $document) use ($customerLoans, $loanSummary, $can, $request) {
                $owner = $document->documentable;
                [$loans, $ownerInfo] = match ($document->documentable_type) {
                    'loan' => [[$loanSummary($owner)], ['type' => 'loan', 'public_id' => $owner->public_id, 'name' => $owner->application_no]],
                    'collateral' => [[$loanSummary($owner->loan)], ['type' => 'collateral', 'public_id' => $owner->public_id, 'name' => $owner->description]],
                    default => [
                        $customerLoans->get($owner->id, collect())->map(fn (LoanParty $p) => $loanSummary($p->loan))->unique('public_id')->values()->all(),
                        ['type' => 'customer', 'public_id' => $owner->public_id, 'name' => $owner->full_name],
                    ],
                };

                return array_merge((new DocumentResource($document))->resolve($request), [
                    'owner' => $ownerInfo,
                    'loans' => $loans,
                    'can' => $can,
                ]);
            })->all();

            return response()->json([
                'status' => 'success',
                'message' => 'Documents retrieved successfully',
                'data' => [
                    'items' => $items,
                    'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
                ],
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve documents', $th);
        }
    }

    public function download(string $publicId)
    {
        try {
            $document = $this->findAccessible($publicId);

            if (! $document) {
                return $this->notFound('Document not found');
            }

            return $this->documents->download($document);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to download document', $th);
        }
    }

    public function update(UpdateDocumentRequest $request, string $publicId)
    {
        try {
            $document = $this->findAccessible($publicId);

            if (! $document) {
                return $this->notFound('Document not found');
            }

            $data = $request->validated();
            $document = $this->documents->update($document, $data, $request->file('file'), $request, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Document updated',
                'data' => new DocumentResource($document->load(['documentType', 'uploader', 'verifier'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update document', $th);
        }
    }

    public function verify(Request $request, string $publicId)
    {
        try {
            $document = $this->findAccessible($publicId);

            if (! $document) {
                return $this->notFound('Document not found');
            }

            $document = $this->documents->verify($document, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Document verified',
                'data' => new DocumentResource($document->load(['documentType', 'uploader', 'verifier'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to verify document', $th);
        }
    }

    public function reject(RejectDocumentRequest $request, string $publicId)
    {
        try {
            $document = $this->findAccessible($publicId);

            if (! $document) {
                return $this->notFound('Document not found');
            }

            $document = $this->documents->reject($document, $request->user(), $request->validated('reason'));

            return response()->json([
                'status' => 'success',
                'message' => 'Document rejected',
                'data' => new DocumentResource($document->load(['documentType', 'uploader', 'verifier'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to reject document', $th);
        }
    }

    public function destroy(string $publicId)
    {
        try {
            $document = $this->findAccessible($publicId);

            if (! $document) {
                return $this->notFound('Document not found');
            }

            $this->documents->delete($document);

            return response()->json([
                'status' => 'success',
                'message' => 'Document deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to delete document', $th);
        }
    }

    protected function findAccessible(string $publicId): ?Document
    {
        $document = Document::with('documentType')->where('public_id', $publicId)->first();
        $owner = $document?->documentable;

        // documentable is loaded through the owner's model, so its branch scope applies here.
        // Collateral has no branch of its own: it is visible when its loan is.
        if ($owner instanceof Collateral) {
            $owner = $owner->loan;
        }

        return $owner ? $document : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Enums\LoanActivityType;
use App\Enums\PartyRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\CreateLoanRequest;
use App\Http\Requests\Loans\LoanRemarksRequest;
use App\Http\Requests\Loans\UpdateLoanRequest;
use App\Http\Resources\Loans\LoanActivityResource;
use App\Http\Resources\Loans\LoanInstallmentResource;
use App\Http\Resources\Loans\LoanListResource;
use App\Http\Resources\Loans\LoanResource;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanActivity;
use App\Services\Loans\LoanApplicationService;
use App\Services\Loans\LoanChecklistService;
use App\Services\Loans\LoanPricingService;
use App\Services\Loans\LoanWorkflowService;
use App\Traits\ActivityLogTrait;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Loans and applications, addressed by public_id. Loans of other branches are invisible (404).
 */
class LoanController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, HandlesApiErrors, PaginatesResources;

    public function __construct(
        protected LoanApplicationService $applications,
        protected LoanPricingService $pricing,
        protected LoanChecklistService $checklist,
        protected LoanWorkflowService $workflow,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Loan Index', only: ['index', 'show', 'schedule', 'activities', 'checklist']),
            new Middleware('permission:Loan Create', only: ['store']),
            new Middleware('permission:Loan Update', only: ['update']),
            new Middleware('permission:Loan Delete', only: ['destroy']),
            new Middleware('permission:Loan Restore', only: ['restore']),
            new Middleware('permission:Loan Note Index', only: ['notes']),
            new Middleware('permission:Loan Note Create', only: ['addNote']),
            new Middleware('permission:Loan Note Update', only: ['updateNote']),
            new Middleware('permission:Loan Note Delete', only: ['destroyNote']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $query = Loan::with(['customer', 'borrowerGroup', 'product', 'branch'])->topLevel()->latest('id');

            // The deleted applications, for whoever may restore them.
            if ($request->boolean('only_deleted')) {
                if (! $request->user()->can('Loan Restore')) {
                    return response()->json(['status' => 'error', 'code' => 'FORBIDDEN', 'message' => 'Only someone who can restore loans may list deleted ones.'], 403);
                }

                $query->onlyTrashed();
            }

            if ($request->filled('search')) {
                $query->search($request->search);
            }

            foreach (['status', 'borrower_type', 'branch_id'] as $filter) {
                if ($request->filled($filter)) {
                    $query->where($filter, $request->get($filter));
                }
            }

            if ($request->filled('product_slug')) {
                $query->whereHas('product', fn ($q) => $q->where('slug', $request->product_slug));
            }

            // One customer's loans (Customer 360): as borrower or co-borrower, as a member of a group loan,
            // and as a guarantor. ?role=borrower or ?role=guarantor narrows it; each row says which role the
            // customer plays (customer_role). A customer outside the user's branch is not found (branch
            // scope), so the list is simply empty.
            if ($request->filled('customer_public_id')) {
                $customerId = Customer::where('public_id', (string) $request->customer_public_id)->value('id') ?? 0;
                $role = (string) $request->input('role', 'any');

                $query->where(function ($q) use ($customerId, $role) {
                    if ($role !== 'guarantor') {
                        $q->where(fn ($b) => $b
                            ->whereHas('parties', fn ($p) => $p->where('customer_id', $customerId)->whereIn('role', PartyRole::borrowingValues()))
                            ->orWhereHas('memberLoans.parties', fn ($p) => $p->where('customer_id', $customerId)));
                    }

                    if ($role !== 'borrower') {
                        $q->orWhereHas('parties', fn ($p) => $p->where('customer_id', $customerId)->where('role', PartyRole::Guarantor->value));
                    }
                });

                $query->with([
                    'parties' => fn ($p) => $p->where('customer_id', $customerId),
                    'memberLoans.parties' => fn ($p) => $p->where('customer_id', $customerId),
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Loans retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), LoanListResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve loans', $th);
        }
    }

    public function store(CreateLoanRequest $request)
    {
        try {
            $loan = $this->applications->create($request->validated(), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Loan application created successfully',
                'data' => new LoanResource($this->withDetails($loan)),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to create loan application', $th);
        }
    }

    public function show(string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Loan retrieved successfully',
                'data' => new LoanResource($this->withDetails($loan)),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve loan', $th);
        }
    }

    public function update(UpdateLoanRequest $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $data = $request->validated();
            $loan = $this->applications->update($loan, $data, $request->user());

            $this->logActivity('UPDATE', 'Loan', "Updated application {$loan->application_no}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Loan application updated successfully',
                'data' => new LoanResource($this->withDetails($loan)),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update loan application', $th);
        }
    }

    /**
     * DELETE /loans/{id} with a reason: only an application that was never paid out.
     */
    public function destroy(LoanRemarksRequest $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $this->applications->delete($loan, $request->validated('remarks'), $request->user());

            $this->logActivity('DELETE', 'Loan', "Deleted application {$loan->application_no}", ['reason' => $request->validated('remarks')]);

            return response()->json([
                'status' => 'success',
                'message' => 'Loan application deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to delete loan application', $th);
        }
    }

    /**
     * POST /loans/{id}/restore: bring a deleted application back (find it with GET /loans?only_deleted=1).
     */
    public function restore(Request $request, string $publicId)
    {
        try {
            $loan = Loan::onlyTrashed()->where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Deleted loan not found');
            }

            $loan = $this->applications->restore($loan, $request->user());

            $this->logActivity('RESTORE', 'Loan', "Restored application {$loan->application_no}");

            return response()->json([
                'status' => 'success',
                'message' => 'Loan application restored successfully',
                'data' => new LoanResource($this->withDetails($loan)),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to restore loan application', $th);
        }
    }

    /**
     * /loans/{id}/notes: the free-text notes of the timeline.
     */
    public function notes(Request $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $query = $loan->activities()->where('type', LoanActivityType::Note->value)->with('performer')->latest('id');

            return response()->json([
                'status' => 'success',
                'message' => 'Loan notes retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), LoanActivityResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve loan notes', $th);
        }
    }

    /**
     * A note can be corrected or removed by the person who wrote it; the old text is kept in the audit log.
     */
    public function updateNote(LoanRemarksRequest $request, string $publicId, int $noteId)
    {
        try {
            $note = $this->ownNote($request, $publicId, $noteId);

            if (! $note) {
                return $this->notFound('Note not found');
            }

            $old = $note->remarks;
            $note->forceFill(['remarks' => $request->validated('remarks')])->save();

            $this->logActivity('UPDATE', 'Loan', "Edited a note on a loan ({$publicId})", ['note_id' => $noteId, 'old' => $old, 'new' => $note->remarks]);

            return response()->json([
                'status' => 'success',
                'message' => 'Note updated successfully',
                'data' => new LoanActivityResource($note->load('performer')),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update note', $th);
        }
    }

    public function destroyNote(Request $request, string $publicId, int $noteId)
    {
        try {
            $note = $this->ownNote($request, $publicId, $noteId);

            if (! $note) {
                return $this->notFound('Note not found');
            }

            $old = $note->remarks;
            $note->delete();

            $this->logActivity('DELETE', 'Loan', "Deleted a note on a loan ({$publicId})", ['note_id' => $noteId, 'old' => $old]);

            return response()->json([
                'status' => 'success',
                'message' => 'Note deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to delete note', $th);
        }
    }

    protected function ownNote(Request $request, string $publicId, int $noteId): ?LoanActivity
    {
        $loan = Loan::where('public_id', $publicId)->first();

        return $loan?->activities()
            ->where('type', LoanActivityType::Note->value)
            ->where('performed_by', $request->user()->id)
            ->find($noteId);
    }

    /**
     * The repayment schedule once disbursed; before that, a preview on the current terms
     * (it is fixed from the disbursement date when the loan is paid out).
     */
    public function schedule(Request $request, string $publicId)
    {
        try {
            $request->validate(['version' => ['nullable', 'string', 'regex:/^(current|all|\d+)$/']]);
            $version = (string) $request->query('version', 'current');

            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $loans = $this->applications->borrowingLoans($loan);

            $schedules = $loans->map(function (Loan $each) use ($version) {
                $installments = $each->installments()->get();

                // The schedule the customer owes now (the latest version) unless asked otherwise:
                // ?version=3 shows that revision only, ?version=all lists every version.
                if ($version !== 'all' && $installments->isNotEmpty()) {
                    $wanted = $version === 'current' ? (int) $installments->max('schedule_version') : (int) $version;
                    $installments = $installments->where('schedule_version', $wanted)->values();
                }

                return [
                    'loan' => ['public_id' => $each->public_id, 'application_no' => $each->application_no, 'loan_no' => $each->loan_no],
                    'is_preview' => $installments->isEmpty(),
                    'schedule' => $installments->isEmpty()
                        ? $this->pricing->price($each, $each->decisionAmount(), $each->rate)->schedule->toArray()
                        : LoanInstallmentResource::collection($installments)->resolve(),
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Loan schedule retrieved successfully',
                'data' => $loan->isGroupFacility() ? ['members' => $schedules->values()] : $schedules->first(),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve loan schedule', $th);
        }
    }

    public function activities(Request $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $query = $loan->activities()->with('performer')->latest('id');

            return response()->json([
                'status' => 'success',
                'message' => 'Loan activities retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), LoanActivityResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve loan activities', $th);
        }
    }

    public function addNote(LoanRemarksRequest $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $activity = $this->workflow->record($loan, LoanActivityType::Note, $request->user(), $request->validated('remarks'));

            return response()->json([
                'status' => 'success',
                'message' => 'Note added successfully',
                'data' => new LoanActivityResource($activity->load('performer')),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to add note', $th);
        }
    }

    public function checklist(string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Loan document checklist retrieved successfully',
                'data' => $this->checklist->forLoan($loan),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to build loan document checklist', $th);
        }
    }

    protected function withDetails(Loan $loan): Loan
    {
        return $loan->load([
            'customer', 'borrowerGroup', 'product', 'branch', 'officer',
            'parties.customer',
            'memberLoans.parties.customer',
            'collaterals.owner', 'collaterals.documents.documentType',
        ]);
    }
}

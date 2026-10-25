<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\JournalExportRequest;
use App\Http\Requests\Accounting\JournalIndexRequest;
use App\Http\Requests\Accounting\ManualJournalRequest;
use App\Http\Requests\Loans\LoanRemarksRequest;
use App\Models\ApprovalRequest;
use App\Http\Resources\Accounting\GlJournalResource;
use App\Http\Resources\Approvals\ApprovalRequestResource;
use App\Models\GlJournal;
use App\Models\Loan;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Accounting\GlReportService;
use App\Support\BranchAccess;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Journals (branch scoped like every other record) and manual journals through maker-checker.
 */
class GlJournalController extends Controller implements HasMiddleware
{
    use HandlesApiErrors, PaginatesResources;

    public function __construct(
        protected GeneralLedgerService $gl,
        protected GlReportService $reports,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:GL Journal Index', only: ['index', 'show', 'export']),
            new Middleware('permission:GL Journal Request', only: ['store']),
            new Middleware('permission:GL Journal Update', only: ['update']),
            new Middleware('permission:GL Journal Delete', only: ['destroy']),
        ];
    }

    public function index(JournalIndexRequest $request)
    {
        try {
            $filters = $request->validated();

            $query = GlJournal::with(['branch', 'loan'])->latest('entry_date')->latest('id');

            foreach (['source', 'status', 'branch_id'] as $filter) {
                if (isset($filters[$filter])) {
                    $query->where($filter, $filters[$filter]);
                }
            }

            if (isset($filters['loan_public_id'])) {
                $query->where('loan_id', Loan::where('public_id', $filters['loan_public_id'])->value('id') ?? 0);
            }

            if (isset($filters['from'])) {
                $query->where('entry_date', '>=', $filters['from']);
            }

            if (isset($filters['to'])) {
                $query->where('entry_date', '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateString());
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Journals retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), GlJournalResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve journals', $th);
        }
    }

    public function show(string $publicId)
    {
        try {
            $journal = GlJournal::with(['branch', 'loan', 'transaction', 'creator', 'approver', 'lines.account'])->where('public_id', $publicId)->first();

            if (! $journal) {
                return $this->notFound('Journal not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Journal retrieved successfully',
                'data' => new GlJournalResource($journal),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve journal', $th);
        }
    }

    /**
     * Maker: saved as pending; it counts in the balances once a second person approves it.
     */
    public function store(ManualJournalRequest $request)
    {
        try {
            $data = $request->validated();
            $restricted = BranchAccess::restrictedBranchId();

            // A branch user enters journals for their own branch only.
            if ($restricted !== null) {
                $data['branch_id'] = $restricted;
            }

            $approval = $this->gl->requestManual($data, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Journal sent for approval',
                'data' => new ApprovalRequestResource($approval->load('requester')),
            ], 202);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to create journal', $th);
        }
    }

    /**
     * Replace your own pending manual journal; the corrected one is sent for approval.
     */
    public function update(ManualJournalRequest $request, string $publicId)
    {
        try {
            $journal = GlJournal::where('public_id', $publicId)->first();

            if (! $journal) {
                return $this->notFound('Journal not found');
            }

            $data = $request->validated();
            $restricted = BranchAccess::restrictedBranchId();

            if ($restricted !== null) {
                $data['branch_id'] = $restricted;
            }

            $approval = $this->gl->updateManual($journal, $data, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Journal replaced and sent for approval',
                'data' => new ApprovalRequestResource($approval->load('requester')),
            ], 202);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update journal', $th);
        }
    }

    /**
     * Withdraw your pending journal (200), or ask for the reversal of a posted manual one (202).
     */
    public function destroy(LoanRemarksRequest $request, string $publicId)
    {
        try {
            $journal = GlJournal::where('public_id', $publicId)->first();

            if (! $journal) {
                return $this->notFound('Journal not found');
            }

            $result = $this->gl->deleteManual($journal, $request->validated('remarks'), $request->user());

            if ($result instanceof ApprovalRequest) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'A reversing journal was sent for approval',
                    'data' => new ApprovalRequestResource($result->load('requester')),
                ], 202);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Journal withdrawn',
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to delete journal', $th);
        }
    }

    /**
     * Posted journal lines as CSV for the accounting package (one year at most per file).
     */
    public function export(JournalExportRequest $request)
    {
        try {
            $filters = $request->validated();

            $from = CarbonImmutable::parse($filters['from']);
            $to = CarbonImmutable::parse($filters['to']);

            if ($from->diffInDays($to) > 366) {
                return response()->json(['status' => 'error', 'code' => 'REPORT_PERIOD_TOO_LONG', 'message' => 'An export covers at most one year.'], 422);
            }

            $rows = $this->reports->exportRows($from, $to, $request->filled('branch_id') ? $request->integer('branch_id') : null);

            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');

                foreach ($rows as $row) {
                    fputcsv($out, $row);
                }

                fclose($out);
            }, "gl-journals-{$from->toDateString()}-to-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv']);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to export journals', $th);
        }
    }
}

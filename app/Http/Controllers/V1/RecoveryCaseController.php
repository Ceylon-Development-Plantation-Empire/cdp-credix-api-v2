<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Enums\RecoveryStage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\LoanRemarksRequest;
use App\Http\Requests\Recovery\AssignRecoveryCaseRequest;
use App\Http\Requests\Recovery\RecoveryActivityRequest;
use App\Http\Resources\Loans\LoanActivityResource;
use App\Http\Resources\Recovery\RecoveryCaseResource;
use App\Models\RecoveryCase;
use App\Models\User;
use App\Services\Recovery\RecoveryService;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Recovery cases for staff. Agencies use AgencyCaseController, which only shows their own cases.
 */
class RecoveryCaseController extends Controller implements HasMiddleware
{
    use HandlesApiErrors, PaginatesResources;

    public function __construct(
        protected RecoveryService $recovery,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Recovery Case Index', only: ['index', 'show']),
            new Middleware('permission:Recovery Case Assign', only: ['assign', 'moveToLegal']),
            new Middleware('permission:Recovery Case Work', only: ['addActivity']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $query = RecoveryCase::with(['loan.customer', 'branch', 'agent'])->latest('id');

            foreach (['stage', 'status', 'branch_id'] as $filter) {
                if ($request->filled($filter)) {
                    $query->where($filter, $request->get($filter));
                }
            }

            if ($request->boolean('mine')) {
                $query->where('assigned_user_id', $request->user()->id);
            }

            if ($request->boolean('unassigned')) {
                $query->whereNull('assigned_user_id');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Recovery cases retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), RecoveryCaseResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve recovery cases', $th);
        }
    }

    public function show(string $publicId)
    {
        try {
            $case = RecoveryCase::with(['loan.customer', 'branch', 'agent'])->where('public_id', $publicId)->first();

            if (! $case) {
                return $this->notFound('Recovery case not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Recovery case retrieved successfully',
                'data' => [
                    'case' => new RecoveryCaseResource($case),
                    'activities' => LoanActivityResource::collection($case->activities()->with('performer')->latest('id')->limit(100)->get()),
                ],
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve recovery case', $th);
        }
    }

    public function assign(AssignRecoveryCaseRequest $request, string $publicId)
    {
        try {
            $case = RecoveryCase::where('public_id', $publicId)->first();

            if (! $case) {
                return $this->notFound('Recovery case not found');
            }

            $agent = User::findOrFail($request->validated('user_id'));
            $case = $this->recovery->assign($case, $agent, RecoveryStage::from($request->validated('stage')), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Recovery case assigned',
                'data' => new RecoveryCaseResource($case->load(['loan.customer', 'branch', 'agent'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to assign recovery case', $th);
        }
    }

    public function moveToLegal(LoanRemarksRequest $request, string $publicId)
    {
        try {
            $case = RecoveryCase::where('public_id', $publicId)->first();

            if (! $case) {
                return $this->notFound('Recovery case not found');
            }

            $case = $this->recovery->moveToLegal($case, $request->validated('remarks'), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Recovery case moved to legal',
                'data' => new RecoveryCaseResource($case->load(['loan.customer', 'branch', 'agent'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update recovery case', $th);
        }
    }

    public function addActivity(RecoveryActivityRequest $request, string $publicId)
    {
        try {
            $case = RecoveryCase::where('public_id', $publicId)->first();

            if (! $case) {
                return $this->notFound('Recovery case not found');
            }

            $activity = $this->recovery->addActivity($case, $request->validated(), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Recovery activity recorded',
                'data' => new LoanActivityResource($activity->load('performer')),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to record recovery activity', $th);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recovery\RecoveryActivityRequest;
use App\Http\Resources\Loans\LoanActivityResource;
use App\Http\Resources\Recovery\RecoveryCaseResource;
use App\Models\Loan;
use App\Models\RecoveryCase;
use App\Services\Recovery\RecoveryService;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * External recovery agencies (user_type = agency, routes behind user.type:agency).
 *
 * An agency is not tied to a branch, so the branch scope is lifted here and replaced by a
 * stricter rule: only the cases assigned to this login, and only while they are open.
 */
class AgencyCaseController extends Controller
{
    use HandlesApiErrors, PaginatesResources;

    public function __construct(
        protected RecoveryService $recovery,
    ) {}

    public function index(Request $request)
    {
        try {
            return response()->json([
                'status' => 'success',
                'message' => 'Assigned recovery cases retrieved successfully',
                'data' => $this->paginated($this->cases($request)->latest('id')->paginate($this->perPage($request)), RecoveryCaseResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve recovery cases', $th);
        }
    }

    public function show(Request $request, string $publicId)
    {
        try {
            $case = $this->cases($request)->where('public_id', $publicId)->first();

            if (! $case) {
                return $this->notFound('Recovery case not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Recovery case retrieved successfully',
                'data' => [
                    'case' => new RecoveryCaseResource($case),
                    // Only the recovery timeline, not the loan's internal approval history.
                    'activities' => LoanActivityResource::collection($case->activities()->with('performer')->latest('id')->limit(100)->get()),
                ],
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve recovery case', $th);
        }
    }

    public function addActivity(RecoveryActivityRequest $request, string $publicId)
    {
        try {
            $case = $this->cases($request)->where('public_id', $publicId)->first();

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

    protected function cases(Request $request): Builder
    {
        return RecoveryCase::withoutGlobalScope('branch')
            ->with(['loan' => fn ($q) => $q->withoutGlobalScope('branch')->with(['customer' => fn ($c) => $c->withoutGlobalScope('branch')])])
            ->where('assigned_user_id', $request->user()->id)
            ->open();
    }
}

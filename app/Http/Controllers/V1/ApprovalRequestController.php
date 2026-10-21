<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Approvals\ApproveRequest;
use App\Http\Requests\Approvals\RejectRequest;
use App\Http\Resources\Approvals\ApprovalRequestResource;
use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalRequestService;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * The approver's inbox. Viewing needs Approval Request Index; deciding a request also needs the
 * permission of its action (for example Product Shariah Approve), which ApprovalRequestService
 * checks, so one screen serves every kind of approval.
 */
class ApprovalRequestController extends Controller implements HasMiddleware
{
    use HandlesApiErrors, PaginatesResources;

    public function __construct(
        protected ApprovalRequestService $approvals,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Approval Request Index', only: ['index', 'show', 'approve', 'reject']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $query = ApprovalRequest::with(['subject', 'requester', 'decider'])->latest('id');

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('action')) {
                $query->where('action', $request->action);
            }

            if ($request->boolean('mine')) {
                $query->where('requested_by', $request->user()->id);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Approval requests retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), ApprovalRequestResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve approval requests', $th);
        }
    }

    public function show(string $publicId)
    {
        try {
            $approval = ApprovalRequest::with(['subject', 'requester', 'decider'])->where('public_id', $publicId)->first();

            if (! $approval) {
                return $this->notFound('Approval request not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Approval request retrieved successfully',
                'data' => new ApprovalRequestResource($approval),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve approval request', $th);
        }
    }

    public function approve(ApproveRequest $request, string $publicId)
    {
        try {
            $approval = ApprovalRequest::findByPublicId($publicId);

            if (! $approval) {
                return $this->notFound('Approval request not found');
            }

            $approval = $this->approvals->approve($approval, $request->user(), $request->validated('reference'), $request->validated('remarks'));

            return response()->json([
                'status' => 'success',
                'message' => 'Request approved successfully',
                'data' => new ApprovalRequestResource($approval->load(['subject', 'requester', 'decider'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to approve request', $th);
        }
    }

    public function reject(RejectRequest $request, string $publicId)
    {
        try {
            $approval = ApprovalRequest::findByPublicId($publicId);

            if (! $approval) {
                return $this->notFound('Approval request not found');
            }

            $approval = $this->approvals->reject($approval, $request->user(), $request->validated('remarks'));

            return response()->json([
                'status' => 'success',
                'message' => 'Request rejected',
                'data' => new ApprovalRequestResource($approval->load(['subject', 'requester', 'decider'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to reject request', $th);
        }
    }

    /**
     * Withdraw your own pending request. No permission beyond being the requester.
     */
    public function cancel(Request $request, string $publicId)
    {
        try {
            $approval = ApprovalRequest::findByPublicId($publicId);

            if (! $approval) {
                return $this->notFound('Approval request not found');
            }

            $approval = $this->approvals->cancel($approval, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Request cancelled',
                'data' => new ApprovalRequestResource($approval->load(['subject', 'requester', 'decider'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to cancel request', $th);
        }
    }
}

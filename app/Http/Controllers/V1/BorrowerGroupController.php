<?php

namespace App\Http\Controllers\V1;

use App\Enums\GroupMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Groups\BorrowerGroupRequest;
use App\Http\Requests\Groups\GroupMemberRequest;
use App\Http\Resources\Groups\BorrowerGroupResource;
use App\Http\Resources\Groups\GroupMemberResource;
use App\Models\BorrowerGroup;
use App\Models\Customer;
use App\Services\Groups\BorrowerGroupService;
use App\Traits\ActivityLogTrait;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class BorrowerGroupController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, HandlesApiErrors, PaginatesResources;

    public function __construct(
        protected BorrowerGroupService $groups,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Borrower Group Index', only: ['index', 'show']),
            new Middleware('permission:Borrower Group Create', only: ['store']),
            new Middleware('permission:Borrower Group Update', only: ['update']),
            new Middleware('permission:Borrower Group Member Manage', only: ['addMember', 'changeRole', 'removeMember']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $query = BorrowerGroup::with('branch')->withCount('currentMembers')->latest('id');

            if ($request->filled('search')) {
                $query->search($request->search);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Borrower groups retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), BorrowerGroupResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve borrower groups', $th);
        }
    }

    public function store(BorrowerGroupRequest $request)
    {
        try {
            $data = $request->validated();
            $group = $this->groups->create($data, $request->user());

            $this->logActivity('CREATE', 'BorrowerGroup', "Created borrower group {$group->group_no}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Borrower group created successfully',
                'data' => new BorrowerGroupResource($group->load('branch')),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to create borrower group', $th);
        }
    }

    public function show(string $publicId)
    {
        try {
            $group = BorrowerGroup::with(['branch', 'currentMembers.customer'])->withCount('currentMembers')->where('public_id', $publicId)->first();

            if (! $group) {
                return $this->notFound('Borrower group not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Borrower group retrieved successfully',
                'data' => new BorrowerGroupResource($group),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve borrower group', $th);
        }
    }

    public function update(BorrowerGroupRequest $request, string $publicId)
    {
        try {
            $group = BorrowerGroup::where('public_id', $publicId)->first();

            if (! $group) {
                return $this->notFound('Borrower group not found');
            }

            $data = $request->validated();
            $group = $this->groups->update($group, $data, $request->user());

            $this->logActivity('UPDATE', 'BorrowerGroup', "Updated borrower group {$group->group_no}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Borrower group updated successfully',
                'data' => new BorrowerGroupResource($group->load(['branch', 'currentMembers.customer'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update borrower group', $th);
        }
    }

    public function addMember(GroupMemberRequest $request, string $publicId)
    {
        try {
            $group = BorrowerGroup::where('public_id', $publicId)->first();

            if (! $group) {
                return $this->notFound('Borrower group not found');
            }

            // The customer is loaded through its branch scope as well.
            $customer = Customer::where('public_id', $request->validated('customer_public_id'))->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            $role = GroupMemberRole::from($request->validated('role') ?? GroupMemberRole::Member->value);
            $member = $this->groups->addMember($group, $customer, $role, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Member added successfully',
                'data' => new GroupMemberResource($member->load('customer')),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to add member', $th);
        }
    }

    public function changeRole(GroupMemberRequest $request, string $publicId, string $memberId)
    {
        try {
            $group = BorrowerGroup::where('public_id', $publicId)->first();
            $member = $group?->members()->find($memberId);

            if (! $member) {
                return $this->notFound('Member not found');
            }

            $member = $this->groups->changeRole($member, GroupMemberRole::from($request->validated('role')));

            return response()->json([
                'status' => 'success',
                'message' => 'Member role updated successfully',
                'data' => new GroupMemberResource($member->load('customer')),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update member role', $th);
        }
    }

    public function removeMember(Request $request, string $publicId, string $memberId)
    {
        try {
            $group = BorrowerGroup::where('public_id', $publicId)->first();
            $member = $group?->members()->find($memberId);

            if (! $member) {
                return $this->notFound('Member not found');
            }

            $member = $this->groups->removeMember($member, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Member removed from the group',
                'data' => new GroupMemberResource($member->load('customer')),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to remove member', $th);
        }
    }
}

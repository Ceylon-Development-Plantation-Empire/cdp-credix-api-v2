<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Users\UserService;
use App\Support\RoleAccess;
use App\Traits\HandlesApiErrors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Back-office user accounts (API guide: Users, roles & staff). Response shapes are the starter
 * kit's: the user model array with its employee relations, role pivots removed.
 *
 * Permissions per action (middleware) + UserPolicy for the admin-account and self rules; the
 * work is done by UserService.
 */
class UserController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    public function __construct(
        protected UserService $users,
        protected UserPolicy $policy,
    ) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:User Index', only: ['index', 'show']),
            new Middleware('permission:User Create', only: ['store']),
            new Middleware('permission:User Update', only: ['update']),
            new Middleware('permission:User Delete', only: ['destroy']),
            new Middleware('permission:User Toggle Status', only: ['toggleStatus']),
        ];
    }

    /**
     * GET /users: paginated, filterable by search, user_type, role, branch_id and is_active.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::with(UserService::SCREEN_RELATIONS)
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $search = (string) $request->input('search');
                $q->where(fn (Builder $b) => $b->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%"));
            })
            ->when($request->filled('user_type'), fn (Builder $q) => $q->where('user_type', $request->input('user_type')))
            ->when($request->filled('role'), fn (Builder $q) => $q->whereHas('roles', fn ($r) => $r->where('name', $request->input('role'))))
            ->when($request->filled('branch_id'), fn (Builder $q) => $q->whereHas('employee', fn ($e) => $e->where('branch_id', $request->input('branch_id'))))
            ->when($request->has('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')));

        $users = $query->paginate(min((int) $request->get('per_page', 15), 100));
        $users->getCollection()->transform(fn (User $user) => $this->present($user));

        return response()->json([
            'status' => 'success',
            'message' => 'Users retrieved successfully',
            'data' => $users,
        ], 200);
    }

    /**
     * POST /users. When no password was given (staff), the generated temporary password is in
     * data.temporary_password, shown once to the administrator and never stored in clear.
     */
    public function store(CreateUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! $this->policy->create($request->user(), $data['user_type'])) {
            return $this->forbidden('Only Super Admin can create admin users');
        }

        if (! RoleAccess::canAssignRole($request->user(), $data['role'])) {
            return $this->forbidden('You cannot assign a role that holds permissions you do not hold yourself');
        }

        $result = $this->users->create($data, $request->user());
        $user = $result['user']->load(UserService::SCREEN_RELATIONS);

        return response()->json([
            'status' => 'success',
            'message' => 'User created successfully',
            'data' => $this->present($user) + array_filter(['temporary_password' => $result['temporary_password']]),
        ], 201);
    }

    /**
     * GET /users/{id}.
     */
    public function show(string $id): JsonResponse
    {
        $user = User::with([...UserService::SCREEN_RELATIONS, 'employee.subordinates.user'])->find($id);

        if (! $user) {
            return $this->notFound('User not found');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'User retrieved successfully',
            'data' => $this->present($user),
        ], 200);
    }

    /**
     * PUT /users/{id}.
     */
    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return $this->notFound('User not found');
        }

        $data = $request->validated();

        if (! $this->policy->update($request->user(), $user, $data['user_type'] ?? null)) {
            return $this->forbidden('Only Super Admin can manage admin users');
        }

        if (isset($data['role'])) {
            if ($user->id === $request->user()->id && ! $user->hasRole($data['role'])) {
                return $this->forbidden('You cannot change your own role');
            }

            if (! RoleAccess::canAssignRole($request->user(), $data['role'])) {
                return $this->forbidden('You cannot assign a role that holds permissions you do not hold yourself');
            }
        }

        $user = $this->users->update($user, $data)->load(UserService::SCREEN_RELATIONS);

        return response()->json([
            'status' => 'success',
            'message' => 'User updated successfully',
            'data' => $this->present($user),
        ], 200);
    }

    /**
     * DELETE /users/{id}: deactivates the account permanently (users are never deleted; their
     * name stays on approvals, postings and the audit trail).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return $this->notFound('User not found');
        }

        if ($user->id === $request->user()->id) {
            return response()->json(['status' => 'error', 'message' => 'You cannot delete your own account'], 422);
        }

        if (! $this->policy->delete($request->user(), $user)) {
            return $this->forbidden('Only Super Admin can delete users');
        }

        $this->users->deactivate($user);

        return response()->json([
            'status' => 'success',
            'message' => 'User deleted successfully',
        ], 200);
    }

    /**
     * PATCH /users/{id}/toggle-status.
     */
    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return $this->notFound('User not found');
        }

        if ($user->id === $request->user()->id) {
            return response()->json(['status' => 'error', 'message' => 'You cannot deactivate your own account'], 422);
        }

        if (! $this->policy->toggleStatus($request->user(), $user)) {
            return $this->forbidden('Only Super Admin can manage admin users');
        }

        $user = $this->users->toggleStatus($user);

        return response()->json([
            'status' => 'success',
            'message' => 'User status updated successfully',
            'data' => ['id' => $user->id, 'is_active' => $user->is_active],
        ], 200);
    }

    /**
     * The starter's user array with the role pivots removed.
     *
     * @return array<string, mixed>
     */
    protected function present(User $user): array
    {
        $data = $user->toArray();

        foreach ($data['roles'] ?? [] as $i => $role) {
            unset($data['roles'][$i]['pivot']);
        }

        return $data;
    }

    /**
     * The starter's 403 body.
     */
    protected function forbidden(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 403);
    }
}

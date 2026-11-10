<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Support\RoleAccess;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Role Index', only: ['index', 'show', 'getAvailableRoles']),
            new Middleware('permission:Role Create', only: ['store']),
            new Middleware('permission:Role Update', only: ['update']),
            new Middleware('permission:Role Delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = min((int) $request->get('per_page', 15), 100);

            $query = Role::with('permissions');

            if ($request->has('search') && $request->search != '') {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('guard_name', 'LIKE', "%{$search}%");
                });
            }

            $query->orderBy('name', 'asc');

            $roles = $query->paginate($perPage);

            if ($roles->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No roles found',
                    'data' => [],
                ], 200);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Roles retrieved successfully',
                'data' => $roles,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve roles',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function create() {}

    public function store(CreateRoleRequest $request)
    {
        try {
            $data = $request->validated();

            // SECURITY: a role cannot hold a permission its creator does not hold.
            $wanted = Permission::whereIn('id', $data['permissions'] ?? [])->pluck('name');
            $denied = RoleAccess::ungrantable($request->user(), $wanted);

            if ($denied !== []) {
                return $this->cannotGrant($denied);
            }

            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => 'api',
                // Only the seeder protects a role; the API never marks one (and never un-marks one).
                'is_protected' => false,
            ]);

            if (isset($data['permissions']) && count($data['permissions']) > 0) {
                $permissions = Permission::whereIn('id', $data['permissions'])->get();
                $role->syncPermissions($permissions);
            }

            $this->logActivity('CREATE', 'Role', "Created role: {$role->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Role created successfully',
                'data' => $role->load('permissions'),
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create role',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $role = Role::with('permissions')->find($id);

            if (! $role) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Role not found',
                    'data' => [],
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Role retrieved successfully',
                'data' => $role,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve role',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function edit(string $id) {}

    public function update(UpdateRoleRequest $request, string $id)
    {
        try {
            $data = $request->validated();

            $role = Role::find($id);

            if (! $role) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Role not found',
                    'data' => [],
                ], 404);
            }

            // The Super Admin role always holds every permission and keeps its name: the code recognises it by name.
            if ($role->name === RoleAccess::SUPER_ADMIN) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The Super Admin role cannot be changed',
                ], 403);
            }

            // A protected (system) role keeps its name for the same reason.
            if ($role->is_protected && isset($data['name']) && $data['name'] !== $role->name) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'A protected system role cannot be renamed',
                ], 422);
            }

            // SECURITY: nobody adds a permission they do not hold themselves (keeping what the role has is fine).
            if (isset($data['permissions'])) {
                $wanted = Permission::whereIn('id', $data['permissions'])->pluck('name');
                $denied = RoleAccess::ungrantable($request->user(), $wanted, $role->permissions->pluck('name'));

                if ($denied !== []) {
                    return $this->cannotGrant($denied);
                }
            }

            if (isset($data['name'])) {
                $role->update(['name' => $data['name']]);
            }

            if (isset($data['permissions'])) {
                $permissions = Permission::whereIn('id', $data['permissions'])->get();
                $role->syncPermissions($permissions);
            }

            $role->load('permissions');

            $this->logActivity('UPDATE', 'Role', "Updated role: {$role->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Role updated successfully',
                'data' => $role,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update role',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $role = Role::find($id);

            if (! $role) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Role not found',
                    'data' => [],
                ], 404);
            }

            if ($role->is_protected) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete protected system role: '.$role->name,
                    'data' => [
                        'role_name' => $role->name,
                        'protected' => true,
                    ],
                ], 422);
            }

            if ($role->users()->count() > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete role. It is assigned to one or more users.',
                    'data' => [
                        'assigned_users_count' => $role->users()->count(),
                    ],
                ], 422);
            }

            $roleName = $role->name;
            $role->delete();

            $this->logActivity('DELETE', 'Role', "Deleted role: {$roleName}");

            return response()->json([
                'status' => 'success',
                'message' => 'Role deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete role',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    protected function cannotGrant(array $denied)
    {
        return response()->json([
            'status' => 'error',
            'code' => 'PERMISSION_CEILING',
            'message' => 'You cannot give a role permissions you do not hold yourself',
            'data' => ['permissions' => $denied],
        ], 403);
    }

    public function getAvailableRoles()
    {
        try {
            $user = auth('api')->user();

            // The roles this user may give to someone else (see RoleAccess).
            $roles = Role::query()
                ->where('guard_name', 'api')
                ->with('permissions:id,name')
                ->orderBy('name')
                ->get()
                ->filter(fn (Role $role) => RoleAccess::canAssignRole($user, $role->name))
                ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name, 'guard_name' => $role->guard_name])
                ->values();

            return response()->json([
                'status' => 'success',
                'message' => 'Roles retrieved successfully',
                'data' => $roles,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve roles',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}

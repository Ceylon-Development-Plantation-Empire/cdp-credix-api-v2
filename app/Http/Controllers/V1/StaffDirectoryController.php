<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\EmployeeDirectoryRequest;
use App\Http\Requests\Staff\UserDirectoryRequest;
use App\Models\Employee;
use App\Models\User;
use App\Support\BranchAccess;
use App\Traits\HandlesApiErrors;
use Illuminate\Database\Eloquent\Builder;

/**
 * Light staff lists for dropdowns (recommended by, reporting manager, recovery agent …).
 *
 * Names, codes and branches only: never phone, NIC or email. A branch user sees their own
 * branch's staff; head office sees all (optionally one branch). At most 200 rows; type to search.
 */
class StaffDirectoryController extends Controller
{
    use HandlesApiErrors;

    public function employees(EmployeeDirectoryRequest $request)
    {
        try {
            $filters = $request->validated();

            $query = Employee::active()
                ->with('branch:id,name')
                ->when($filters['search'] ?? null, fn (Builder $q, string $s) => $q->search($s));
            $this->scopeToBranch($query, 'branch_id', $request->filled('branch_id') ? $request->integer('branch_id') : null);

            return response()->json([
                'status' => 'success',
                'message' => 'Employees retrieved successfully',
                'data' => $query->orderBy('full_name')->limit(200)->get()->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'employee_code' => $e->employee_code,
                    'full_name' => $e->full_name,
                    'branch' => $e->branch ? ['id' => $e->branch->id, 'name' => $e->branch->name] : null,
                ]),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve employees', $th);
        }
    }

    public function users(UserDirectoryRequest $request)
    {
        try {
            $filters = $request->validated();

            $query = User::query()
                ->where('is_active', true)
                ->whereIn('user_type', [...UserType::backOffice(), UserType::Agency->value])
                ->with('employee.branch:id,name')
                ->when($filters['search'] ?? null, fn (Builder $q, string $s) => $q->where(fn (Builder $w) => $w
                    ->where('name', 'like', "%{$s}%")->orWhere('username', 'like', "%{$s}%")));

            // e.g. ?permission=Recovery Case Work for the agents a case can be assigned to
            if (! empty($filters['permission'])) {
                $query->permission($filters['permission']);
            }

            if (! empty($filters['user_type'])) {
                $query->where('user_type', $filters['user_type']);
            }

            $restricted = BranchAccess::restrictedBranchId();
            $branchId = $restricted ?? ($request->filled('branch_id') ? $request->integer('branch_id') : null);

            // DECISION: recovery agencies serve every branch and have no employee record, so a branch
            // filter keeps them; otherwise a branch recovery manager could never assign one.
            if ($branchId !== null) {
                $query->where(fn (Builder $w) => $w
                    ->whereHas('employee', fn (Builder $q) => $q->where('branch_id', $branchId))
                    ->orWhere('user_type', UserType::Agency->value));
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Users retrieved successfully',
                'data' => $query->orderBy('name')->limit(200)->get()->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'username' => $u->username,
                    'user_type' => $u->user_type,
                    'branch' => $u->employee?->branch ? ['id' => $u->employee->branch->id, 'name' => $u->employee->branch->name] : null,
                ]),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve users', $th);
        }
    }

    protected function scopeToBranch(Builder $query, string $column, ?int $requested): void
    {
        $branchId = BranchAccess::restrictedBranchId() ?? $requested;

        if ($branchId !== null) {
            $query->where($column, $branchId);
        }
    }
}

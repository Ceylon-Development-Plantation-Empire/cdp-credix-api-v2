<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\EmployeeRequest;
use App\Models\Employee;
use App\Support\BranchAccess;
use App\Traits\ActivityLogTrait;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * The HR records of staff. A login is created with the user (POST /users); this is for the record on its
 * own: people who work for the company without a login, and corrections to the record of those who have one.
 * Records are never erased (their name is on approvals and postings): delete deactivates them.
 * A branch user works with their own branch's employees only.
 */
class EmployeeController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, HandlesApiErrors, PaginatesResources;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Employee Index', only: ['index', 'show']),
            new Middleware('permission:Employee Create', only: ['store']),
            new Middleware('permission:Employee Update', only: ['update']),
            new Middleware('permission:Employee Delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $query = $this->scoped(Employee::query())->with(['branch:id,name', 'designation:id,name', 'user:id,employee_id,is_active'])
                ->search($request->input('search'))
                ->when($request->filled('branch_id'), fn (Builder $q) => $q->where('branch_id', $request->integer('branch_id')))
                ->when($request->has('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
                ->orderBy('full_name');

            $page = $query->paginate($this->perPage($request));

            return response()->json([
                'status' => 'success',
                'message' => 'Employees retrieved successfully',
                'data' => [
                    'items' => $page->getCollection()->map(fn (Employee $e) => $this->present($e))->values(),
                    'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
                ],
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve employees', $th);
        }
    }

    public function show(int $id)
    {
        try {
            $employee = $this->scoped(Employee::query())->with(['branch:id,name', 'designation:id,name', 'user:id,employee_id,is_active', 'reportingManager:id,full_name'])->find($id);

            if (! $employee) {
                return $this->notFound('Employee not found');
            }

            return response()->json(['status' => 'success', 'message' => 'Employee retrieved successfully', 'data' => $this->present($employee)], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve employee', $th);
        }
    }

    public function store(EmployeeRequest $request)
    {
        try {
            $data = $this->attributes($request->validated());

            // A branch user records employees of their own branch only.
            if (($restricted = BranchAccess::restrictedBranchId()) !== null) {
                $data['branch_id'] = $restricted;
            }

            $employee = Employee::create($data + [
                'employee_type' => 'permanent',
                'id_type' => 'nic',
                'date_of_birth' => '2000-01-01',
                'phone_primary' => '0000000000',
                'is_active' => true,
            ]);

            $this->logActivity('CREATE', 'Employee', "Created employee: {$employee->employee_code}", collect($data)->except(['id_number'])->all());

            return response()->json(['status' => 'success', 'message' => 'Employee created successfully', 'data' => $this->present($employee->load(['branch:id,name', 'designation:id,name']))], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to create employee', $th);
        }
    }

    public function update(EmployeeRequest $request, int $id)
    {
        try {
            $employee = $this->scoped(Employee::query())->find($id);

            if (! $employee) {
                return $this->notFound('Employee not found');
            }

            $data = $this->attributes($request->validated());

            // A branch user cannot move an employee to another branch.
            if (($restricted = BranchAccess::restrictedBranchId()) !== null) {
                unset($data['branch_id']);
            }

            $employee->update($data);

            $this->logActivity('UPDATE', 'Employee', "Updated employee: {$employee->employee_code}", collect($data)->except(['id_number'])->all());

            return response()->json(['status' => 'success', 'message' => 'Employee updated successfully', 'data' => $this->present($employee->load(['branch:id,name', 'designation:id,name']))], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update employee', $th);
        }
    }

    /**
     * Deactivate. Refused while the employee still has an active login (deactivate the user first) or
     * active people reporting to them.
     */
    public function destroy(int $id)
    {
        try {
            $employee = $this->scoped(Employee::query())->find($id);

            if (! $employee) {
                return $this->notFound('Employee not found');
            }

            if ($employee->user()->where('is_active', true)->exists()) {
                return response()->json(['status' => 'error', 'code' => 'EMPLOYEE_HAS_LOGIN', 'message' => 'This employee still has an active login. Deactivate the user first.'], 409);
            }

            if ($employee->subordinates()->where('is_active', true)->exists()) {
                return response()->json(['status' => 'error', 'code' => 'EMPLOYEE_HAS_REPORTS', 'message' => 'Other employees still report to this person. Reassign them first.'], 409);
            }

            $employee->forceFill(['is_active' => false, 'end_date' => $employee->end_date ?? now()->toDateString()])->save();

            $this->logActivity('DELETE', 'Employee', "Deactivated employee (records are never erased): {$employee->employee_code}");

            return response()->json(['status' => 'success', 'message' => 'Employee deleted successfully'], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to delete employee', $th);
        }
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    protected function scoped(Builder $query): Builder
    {
        $restricted = BranchAccess::restrictedBranchId();

        return $restricted === null ? $query : $query->where('branch_id', $restricted);
    }

    /**
     * Request fields as employee columns (the name is split the way the user screen does).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function attributes(array $data): array
    {
        if (isset($data['full_name'])) {
            $parts = explode(' ', trim($data['full_name']));
            $data['f_name'] = $parts[0];
            $data['l_name'] = count($parts) > 1 ? end($parts) : $parts[0];
            $data['name_with_initials'] = $data['full_name'];
        }

        if (isset($data['email'])) {
            $data['email'] = mb_strtolower($data['email']);
        }

        return $data;
    }

    /**
     * Never the ID number: it is shown only where it is needed, masked, and not in a list.
     *
     * @return array<string, mixed>
     */
    protected function present(Employee $e): array
    {
        return [
            'id' => $e->id,
            'employee_code' => $e->employee_code,
            'full_name' => $e->full_name,
            'email' => $e->email,
            'phone' => $e->phone_primary,
            'employee_type' => $e->employee_type,
            'is_active' => (bool) $e->is_active,
            'start_date' => $e->start_date?->toDateString(),
            'end_date' => $e->end_date?->toDateString(),
            'branch' => $e->branch ? ['id' => $e->branch->id, 'name' => $e->branch->name] : null,
            'designation' => $e->designation ? ['id' => $e->designation->id, 'name' => $e->designation->name] : null,
            'reporting_manager' => $e->relationLoaded('reportingManager') && $e->reportingManager ? ['id' => $e->reportingManager->id, 'name' => $e->reportingManager->full_name] : null,
            'has_login' => $e->relationLoaded('user') ? $e->user !== null : null,
        ];
    }
}

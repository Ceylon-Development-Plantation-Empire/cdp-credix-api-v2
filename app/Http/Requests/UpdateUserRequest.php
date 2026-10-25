<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Models\User;
use App\Rules\UniqueEmployeeIdNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * PUT /users/{id}: any subset of the create fields.
 *
 * A password set here is an administrator reset: the user must choose their own afterwards
 * (must_change_password) and every existing session of that user ends.
 */
class UpdateUserRequest extends FormRequest
{
    use FormatsValidationErrors;

    /**
     * Permission "User Update" is checked by the controller middleware; the admin-only rule by UserPolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $id = (int) $this->route('user');
        $employeeId = User::query()->whereKey($id)->value('employee_id');

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'username' => ['sometimes', 'nullable', 'string', 'max:60', Rule::unique('users', 'username')->ignore($id)],
            'email' => ['sometimes', 'email:rfc', 'max:150', Rule::unique('users', 'email')->ignore($id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'password' => ['sometimes', 'string', 'max:255', Password::defaults()],
            'user_type' => ['sometimes', 'in:admin,staff,agency'],
            'role' => ['sometimes', 'string', 'exists:roles,name'],

            /* Staff: the embedded employee record */
            'employee_code' => ['sometimes', 'string', 'max:30', Rule::unique('employees', 'employee_code')->ignore($employeeId)],
            'id_number' => ['sometimes', 'string', 'max:30', new UniqueEmployeeIdNumber($employeeId)],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'zonal_id' => ['nullable', 'exists:org_units,id,unit_type,zone'],
            'region_id' => ['nullable', 'exists:org_units,id,unit_type,region'],
            'province_id' => ['nullable', 'exists:org_units,id,unit_type,province'],
            'designation_id' => ['sometimes', 'filled', 'exists:designations,id'],
            'reporting_manager_id' => ['nullable', 'exists:employees,id'],

            'is_active' => ['sometimes', 'boolean'],
            'can_login' => ['sometimes', 'boolean'],
        ];
    }
}

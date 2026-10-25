<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Rules\UniqueEmployeeIdNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * POST /users: create an admin or a staff login (staff also get their employee record).
 *
 * Changes from the starter kit (API guide v2.1):
 * - email is required: it is the sign-in identifier (owner decision: email + password);
 * - username is optional for every type (a display handle only);
 * - password, when given, follows Password::defaults() (12+ characters, mixed case, number, symbol).
 *   When it is left out for staff, a random temporary password is generated and returned once.
 */
class CreateUserRequest extends FormRequest
{
    use FormatsValidationErrors;

    /**
     * Permission "User Create" is checked by the controller middleware; the admin-only rule by UserPolicy.
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
        return [
            'name' => ['required', 'string', 'max:150'],
            'username' => ['nullable', 'string', 'max:60', 'unique:users,username'],
            'email' => ['required', 'email:rfc', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required_if:user_type,admin', 'nullable', 'string', 'max:255', Password::defaults()],
            'user_type' => ['required', 'in:admin,staff,agency'],
            'role' => ['required', 'string', 'exists:roles,name'],

            /* Staff: the embedded employee record */
            'employee_code' => ['required_if:user_type,staff', 'nullable', 'string', 'max:30', 'unique:employees,employee_code'],
            'id_number' => ['required_if:user_type,staff', 'nullable', 'string', 'max:30', new UniqueEmployeeIdNumber],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'zonal_id' => ['nullable', 'exists:org_units,id,unit_type,zone'],
            'region_id' => ['nullable', 'exists:org_units,id,unit_type,region'],
            'province_id' => ['nullable', 'exists:org_units,id,unit_type,province'],
            'designation_id' => ['required_if:user_type,staff', 'nullable', 'exists:designations,id'],
            'reporting_manager_id' => ['nullable', 'exists:employees,id'],

            'is_active' => ['sometimes', 'boolean'],
            'can_login' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Enums\LookupType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Models\Employee;
use App\Rules\LookupCode;
use App\Rules\UniqueEmployeeIdNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An HR record on its own (no login). On update every field is optional.
 */
class EmployeeRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $create = $this->isMethod('post');
        $id = $create ? null : (int) $this->route('id');
        $required = $create ? 'required' : 'sometimes';

        return [
            'full_name' => [$required, 'string', 'max:150'],
            'employee_code' => [$required, 'string', 'max:30', Rule::unique('employees', 'employee_code')->ignore($id)],
            'id_type' => ['sometimes', 'string', 'max:20'],
            'id_number' => [$required, 'string', 'max:30', new UniqueEmployeeIdNumber($id)],
            'date_of_birth' => ['sometimes', 'date_format:Y-m-d', 'before:today'],
            'email' => [$required, 'email:rfc', 'max:150'],
            'phone_primary' => ['sometimes', 'string', 'max:20'],
            'employee_type' => ['sometimes', 'string', new LookupCode(LookupType::EmployeeType)],
            'designation_id' => [$required, 'exists:designations,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'zonal_id' => ['nullable', 'exists:org_units,id,unit_type,zone'],
            'region_id' => ['nullable', 'exists:org_units,id,unit_type,region'],
            'province_id' => ['nullable', 'exists:org_units,id,unit_type,province'],
            'reporting_manager_id' => ['nullable', 'exists:employees,id', $id ? Rule::notIn([$id]) : 'nullable'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

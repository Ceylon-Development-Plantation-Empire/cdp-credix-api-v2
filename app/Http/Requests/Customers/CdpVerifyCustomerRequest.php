<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET or POST /cdp/verify-customer. The id types are spelled the way CDP Connect expects them.
 */
class CdpVerifyCustomerRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_number' => ['required', 'string', 'max:100'],
            'id_type' => ['nullable', 'string', 'in:nic,passport,driving_license,other'],
            'status' => ['nullable', 'string', 'in:all,approved,expired,pending'],
        ];
    }
}

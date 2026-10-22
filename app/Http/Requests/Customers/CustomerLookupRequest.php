<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Enums\IdType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /customers/lookup: "is this person already registered?" across all branches.
 *
 * Validation errors keep the documented {message, errors: {field: [...]}} shape.
 */
class CustomerLookupRequest extends FormRequest
{
    /**
     * Permission "Customer Index" is checked by the controller middleware.
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
            'id_type' => ['required', Rule::enum(IdType::class)],
            'id_number' => ['required', 'string', 'max:30'],
        ];
    }
}

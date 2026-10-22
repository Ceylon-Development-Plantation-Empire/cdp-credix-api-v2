<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Enums\LookupType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Rules\LookupCode;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a guarantor to a loan application. The relationship is a code of the
 * guarantor_relationship lookup list (family, spouse, friend, employer …), managed as data.
 */
class AddGuarantorRequest extends FormRequest
{
    use FormatsValidationErrors;

    /**
     * Permission is checked by the controller middleware (Loan Guarantor Manage).
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
            'customer_public_id' => ['required', 'string', 'ulid'],
            'relationship' => ['nullable', 'string', new LookupCode(LookupType::GuarantorRelationship)],
        ];
    }
}

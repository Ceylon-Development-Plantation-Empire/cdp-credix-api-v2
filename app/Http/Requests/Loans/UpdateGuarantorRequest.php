<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Enums\LookupType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Rules\LookupCode;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correct a guarantor's relationship code. The person cannot change: remove and add again.
 */
class UpdateGuarantorRequest extends FormRequest
{
    use FormatsValidationErrors;

    /**
     * Permission is checked by the controller middleware (Loan Guarantor Update).
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
            'relationship' => ['nullable', 'string', new LookupCode(LookupType::GuarantorRelationship)],
        ];
    }
}

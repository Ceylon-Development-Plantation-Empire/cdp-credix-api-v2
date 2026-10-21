<?php

namespace App\Http\Requests\Loans;

use App\Enums\CollateralType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One pledged item. The details asked for depend on the type (deed number for property, chassis
 * number for a vehicle, karat and weight for gold …), see CollateralType::detailRules().
 *
 * Gold is valued from its weight, purity and the 24K rate, so market_value is not taken for gold.
 */
class CollateralRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = CollateralType::tryFrom((string) $this->input('type'));

        $rules = [
            'type' => ['required', Rule::enum(CollateralType::class)],
            'owner_customer_public_id' => ['nullable', 'string', 'ulid'],
            'description' => ['required', 'string', 'max:255'],
            'details' => ['required', 'array'],
            'market_value' => [$type?->isValuedByWeight() ? 'prohibited' : 'required', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'forced_sale_value' => ['nullable', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'valued_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'valued_by' => ['nullable', 'string', 'max:150'],
        ];

        foreach ($type?->detailRules() ?? [] as $field => $fieldRules) {
            $rules["details.{$field}"] = $fieldRules;
        }

        return $rules;
    }
}

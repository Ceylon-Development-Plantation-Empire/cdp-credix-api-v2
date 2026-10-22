<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\LookupType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Http\Requests\Settings\Concerns\CollateralTypeMetaRules;
use App\Support\Lookups\CustomFields;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /lookups: add a value to a code list (a bank, a relationship, an income category, a
 * custom field …). The code is stable once created; the label can be changed later.
 */
class StoreLookupRequest extends FormRequest
{
    use CollateralTypeMetaRules;
    use FormatsValidationErrors;

    /**
     * Permission "Lookup Manage" is checked by the controller middleware.
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
        $collateral = $this->input('type') === LookupType::CollateralType->value;

        return ($collateral ? $this->collateralTypeMetaRules() : []) + [
            'type' => ['required', Rule::enum(LookupType::class)],
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.\-]+$/', Rule::unique('lookups', 'code')->where('type', $this->input('type'))],
            'label' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'meta' => ['nullable', 'array'],
            'meta.weight' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            // Custom-field definitions only: the field's validation rules, from a safe whitelist.
            'meta.rules' => ['sometimes', 'array', 'max:10'],
            'meta.rules.*' => ['string', 'max:100', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! CustomFields::isAllowedRule($value)) {
                    $fail('This validation rule is not allowed on a custom field.');
                }
            }],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }
}

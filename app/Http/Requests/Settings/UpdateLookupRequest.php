<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\LookupType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Http\Requests\Settings\Concerns\CollateralTypeMetaRules;
use App\Models\Lookup;
use App\Support\Lookups\CustomFields;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /lookups/{id}: relabel, reorder, (de)activate or end-date a value. The type and code never
 * change (records point at them); to replace a value, end-date it and add a new one.
 */
class UpdateLookupRequest extends FormRequest
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
        $collateral = Lookup::query()->find($this->route('id'))?->type === LookupType::CollateralType;

        return ($collateral ? $this->collateralTypeMetaRules() : []) + [
            'type' => ['prohibited'],
            'code' => ['prohibited'],
            'label' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'meta' => ['nullable', 'array'],
            'meta.weight' => ['sometimes', 'integer', 'min:0', 'max:1000'],
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

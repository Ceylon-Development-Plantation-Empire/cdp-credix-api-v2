<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Enums\ChargeCalculation;
use App\Enums\ChargeRecurrence;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Support\Charges\ChargeTypeRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /charge-types/{code}: rename, regroup, reorder or narrow a fee type. The code and the
 * behaviour never change; system types cannot be deactivated. Products keep their own charge
 * rows and loans keep their terms snapshot, so this never changes a running loan.
 */
class UpdateChargeTypeRequest extends FormRequest
{
    use FormatsValidationErrors;

    /**
     * Permission "Charge Type Manage" is checked by the controller middleware.
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
        $type = app(ChargeTypeRegistry::class)->find((string) $this->route('code'));
        $calculations = $type ? array_map(fn (ChargeCalculation $c) => $c->value, $type->behaviour->permittedCalculations()) : [];
        $recurrences = $type ? array_map(fn (ChargeRecurrence $r) => $r->value, $type->behaviour->permittedRecurrences()) : [];

        return [
            'code' => ['prohibited'],
            'behaviour' => ['prohibited'],
            'name' => ['sometimes', 'string', 'max:100'],
            'display_group' => ['sometimes', 'string', 'max:100'],
            'allowed_calculations' => ['sometimes', 'array', 'min:1'],
            'allowed_calculations.*' => ['distinct', Rule::in($calculations)],
            'allowed_recurrences' => ['sometimes', 'array', 'min:1'],
            'allowed_recurrences.*' => ['distinct', Rule::in($recurrences)],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean', $type?->is_system ? 'accepted' : 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_active.accepted' => 'A built-in charge type cannot be switched off.',
        ];
    }
}

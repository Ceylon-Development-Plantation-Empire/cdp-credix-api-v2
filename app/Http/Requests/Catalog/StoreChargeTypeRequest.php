<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Enums\ChargeBehaviour;
use App\Enums\ChargeCalculation;
use App\Enums\ChargeRecurrence;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /charge-types: a new fee type (e.g. "legal fee").
 *
 * New types are always upfront fees (taken at disbursement or added to the first installment):
 * the engine-raised behaviours (late payment, early settlement, safekeeping) exist once each as
 * system types. The calculations and recurrences must fit the upfront behaviour.
 */
class StoreChargeTypeRequest extends FormRequest
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
        $behaviour = ChargeBehaviour::Upfront;

        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('charge_types', 'code')],
            'name' => ['required', 'string', 'max:100'],
            'display_group' => ['required', 'string', 'max:100'],
            'behaviour' => ['sometimes', Rule::in([$behaviour->value])],
            'allowed_calculations' => ['required', 'array', 'min:1'],
            'allowed_calculations.*' => ['distinct', Rule::in(array_map(fn (ChargeCalculation $c) => $c->value, $behaviour->permittedCalculations()))],
            'allowed_recurrences' => ['required', 'array', 'min:1'],
            'allowed_recurrences.*' => ['distinct', Rule::in(array_map(fn (ChargeRecurrence $r) => $r->value, $behaviour->permittedRecurrences()))],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'behaviour.in' => 'New fee types are upfront fees; late payment, early settlement and safekeeping are built in.',
        ];
    }
}

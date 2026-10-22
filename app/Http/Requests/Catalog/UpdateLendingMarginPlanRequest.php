<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /lending-margin-plans/{code}: rename, change the percentage, reorder or (de)activate a plan.
 * The code and the collateral type never change (pledges point at them); a new percentage applies
 * to collateral added from now on, never to items already valued.
 */
class UpdateLendingMarginPlanRequest extends FormRequest
{
    use FormatsValidationErrors;

    /**
     * Permission "Lending Margin Manage" is checked by the controller middleware.
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
            'code' => ['prohibited'],
            'collateral_type' => ['prohibited'],
            'name' => ['sometimes', 'string', 'max:120'],
            'percentage' => ['sometimes', 'decimal:0,2', 'gt:0', 'max:100'],
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
            'code.prohibited' => 'The code of a plan cannot change; add a new plan instead.',
            'collateral_type.prohibited' => 'A plan cannot move to another collateral type; add a new plan instead.',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Enums\LookupType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Rules\LookupCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /lending-margin-plans: a new plan for a collateral type, e.g. "Property Mortgage Plan 4" at
 * 55 %. Only the type, the name and the percentage are needed; the code is made from the name when
 * it is not given.
 */
class StoreLendingMarginPlanRequest extends FormRequest
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
            'collateral_type' => ['required', 'string', new LookupCode(LookupType::CollateralType)],
            'name' => ['required', 'string', 'max:120'],
            'percentage' => ['required', 'decimal:0,2', 'gt:0', 'max:100'],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/', Rule::unique('lending_margin_plans', 'code')],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

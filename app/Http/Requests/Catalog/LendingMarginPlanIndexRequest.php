<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /lending-margin-plans and /lending-margin-plans/options: optional filters.
 */
class LendingMarginPlanIndexRequest extends FormRequest
{
    use FormatsValidationErrors;

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
            'collateral_type' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }
}

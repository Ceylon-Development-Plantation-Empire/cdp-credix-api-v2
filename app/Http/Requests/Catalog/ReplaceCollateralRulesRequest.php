<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Catalog\Concerns\ProductFieldRules;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceCollateralRulesRequest extends FormRequest
{
    use FormatsValidationErrors, ProductFieldRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'collateral_rules' => ['present', 'array', 'max:10'],
            ...$this->collateralRuleRules('collateral_rules'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Enums\CollateralValuation;
use App\Enums\LookupType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Rules\LookupCode;
use App\Support\BusinessDate;
use App\Support\Collateral\CollateralTypes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One pledged item. type is a code of the collateral_type lookup list; the details asked for are
 * that type's fields (deed number for property, chassis number for a vehicle, karat and weight
 * for gold …), see App\Support\Collateral\CollateralTypes.
 *
 * lending_margin_plan is optional: the code of a plan of the same collateral type. Without one,
 * the product's lending % for the type is used.
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
        $types = app(CollateralTypes::class);
        $code = (string) $this->input('type');
        $known = $code !== '' && $types->exists($code);
        $byWeight = $known && $types->valuation($code) === CollateralValuation::GoldWeight;

        $rules = [
            'type' => ['required', 'string', new LookupCode(LookupType::CollateralType)],
            'lending_margin_plan' => ['nullable', 'string', 'max:40'],
            'owner_customer_public_id' => ['nullable', 'string', 'ulid'],
            'description' => ['required', 'string', 'max:255'],
            // A type with no fields of its own asks for no details.
            'details' => ['sometimes', 'array'],
            'market_value' => [$byWeight ? 'prohibited' : 'required', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'forced_sale_value' => ['nullable', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'valued_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'valued_by' => ['nullable', 'string', 'max:150'],
        ];

        foreach ($known ? $types->fieldRules($code) : [] as $field => $fieldRules) {
            $rules["details.{$field}"] = $fieldRules;
        }

        return $rules;
    }
}

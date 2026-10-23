<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings\Concerns;

use App\Enums\CollateralReference;
use App\Enums\CollateralValuation;
use App\Enums\DocumentTarget;
use App\Support\Lookups\CustomFields;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Rules for the meta of a collateral type (lookups of type collateral_type):
 *
 *   {
 *     "valuation": "market_value" | "gold_weight",          optional, default market_value
 *     "reference": "registration" | "custody",              optional, default by valuation
 *     "fields":    {"machine_no": ["required", "string", "max:40"], "colour": []},   optional
 *     "documents": ["valuation-report"]                     optional, collateral document types
 *   }
 *
 * Nothing in it is mandatory: a type with no fields and no documents asks only for its value.
 * SECURITY: field rules come from the CustomFields whitelist (no exists / unique / regex), so a type
 * definition can never be used to probe data.
 */
trait CollateralTypeMetaRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function collateralTypeMetaRules(): array
    {
        return [
            'meta.valuation' => ['sometimes', Rule::enum(CollateralValuation::class)],
            'meta.reference' => ['sometimes', Rule::enum(CollateralReference::class)],
            'meta.fields' => ['sometimes', 'array', 'max:30', function (string $attribute, mixed $value, Closure $fail) {
                foreach (array_keys((array) $value) as $key) {
                    if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
                        $fail('Field names are lower-case letters, digits and underscores, starting with a letter (e.g. machine_no).');

                        return;
                    }
                }
            }],
            'meta.fields.*' => ['array', 'max:10'],
            'meta.fields.*.*' => ['string', 'max:100', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! CustomFields::isAllowedRule($value)) {
                    $fail('This validation rule is not allowed on a collateral field.');
                }
            }],
            'meta.documents' => ['sometimes', 'array', 'max:20'],
            'meta.documents.*' => ['string', 'distinct', Rule::exists('document_types', 'slug')->where('target', DocumentTarget::Collateral->value)],
            'meta.optional_documents' => ['sometimes', 'array', 'max:20'],
            'meta.optional_documents.*' => ['string', 'distinct', 'not_in:'.implode(',', (array) $this->input('meta.documents', [])), Rule::exists('document_types', 'slug')->where('target', DocumentTarget::Collateral->value)],
        ];
    }
}

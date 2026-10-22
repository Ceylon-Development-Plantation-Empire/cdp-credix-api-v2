<?php

declare(strict_types=1);

namespace App\Support\Collateral;

use App\Enums\CollateralReference;
use App\Enums\CollateralValuation;
use App\Enums\LookupType;
use App\Support\BusinessDate;
use App\Support\Lookups\CustomFields;
use App\Support\Lookups\LookupRegistry;

/**
 * Collateral types are data: lookups of type collateral_type, so head office adds a new kind of
 * security (machinery, shares, land without a deed …) without a release. Each type's meta says:
 *
 *   valuation  market_value | gold_weight          how its value is found (App\Enums\CollateralValuation)
 *   reference  registration | custody              what proves it is perfected (App\Enums\CollateralReference)
 *   fields     {key: [rules]}                       the details asked for, rules from the CustomFields whitelist
 *   documents  [document type slugs]               proof documents checked before verification
 *
 * A field without rules is optional, and a type without fields or documents asks for nothing
 * beyond its value: nothing is mandatory unless head office makes it so. The only exception is
 * the gold valuation, whose weight and karat fields the engine needs (CollateralValuation::engineFields).
 *
 * DECISION: a new type is a lookup, not a table: types are short lists with a few settings each,
 * and the lookups table already gives stable codes, real foreign keys, deactivation instead of
 * deletion, caching and an audited admin API.
 */
final class CollateralTypes
{
    /**
     * The types the system starts with (seeded, is_system). Their codes are what the API has always
     * accepted, so nothing changes for existing clients. Head office may relabel them and change
     * their fields and documents; the valuation and reference of a system type are fixed.
     *
     * Rule tokens: "today" in a date rule is the Sri Lankan business date; "next_year" is next year.
     */
    public const SYSTEM = [
        'property_mortgage' => [
            'label' => 'Property mortgage',
            'meta' => [
                'valuation' => 'market_value',
                'reference' => 'registration',
                'fields' => [
                    'deed_no' => ['required', 'string', 'max:50'],
                    'deed_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
                    'notary' => ['nullable', 'string', 'max:150'],
                    'plan_no' => ['nullable', 'string', 'max:50'],
                    'lot_no' => ['nullable', 'string', 'max:20'],
                    'extent' => ['required', 'string', 'max:50'],
                    'address' => ['required', 'string', 'max:255'],
                ],
                'documents' => ['property-deed', 'valuation-report'],
            ],
        ],
        'vehicle' => [
            'label' => 'Vehicle',
            'meta' => [
                'valuation' => 'market_value',
                'reference' => 'registration',
                'fields' => [
                    'registration_no' => ['required', 'string', 'max:20'],
                    'chassis_no' => ['required', 'string', 'max:40'],
                    'engine_no' => ['required', 'string', 'max:40'],
                    'make' => ['required', 'string', 'max:50'],
                    'model' => ['required', 'string', 'max:50'],
                    'year' => ['required', 'integer', 'min:1950', 'max:next_year'],
                ],
                'documents' => ['vehicle-cr', 'valuation-report'],
            ],
        ],
        'gold' => [
            'label' => 'Gold',
            'meta' => [
                'valuation' => 'gold_weight',
                'reference' => 'custody',
                'fields' => [
                    'item' => ['required', 'string', 'max:100'],
                ],
                'documents' => ['gold-valuation-slip'],
            ],
        ],
        'fixed_deposit' => [
            'label' => 'Fixed deposit lien',
            'meta' => [
                'valuation' => 'market_value',
                'reference' => 'registration',
                'fields' => [
                    'account_no' => ['required', 'string', 'max:40'],
                    'institution' => ['required', 'string', 'max:150'],
                    'maturity_date' => ['nullable', 'date_format:Y-m-d'],
                ],
                'documents' => [],
            ],
        ],
        'cdp_investment' => [
            'label' => 'CDP investment',
            'meta' => [
                'valuation' => 'market_value',
                'reference' => 'registration',
                'fields' => [
                    'account_no' => ['required', 'string', 'max:40'],
                    'institution' => ['required', 'string', 'max:150'],
                    'maturity_date' => ['nullable', 'date_format:Y-m-d'],
                ],
                'documents' => [],
            ],
        ],
    ];

    public function __construct(
        private readonly LookupRegistry $lookups,
    ) {}

    public function exists(string $code): bool
    {
        return $this->lookups->metaOf(LookupType::CollateralType, $code) !== null;
    }

    public function label(string $code): string
    {
        foreach ($this->lookups->values(LookupType::CollateralType) as $value) {
            if ($value['code'] === $code) {
                return $value['label'];
            }
        }

        return $code;
    }

    public function valuation(string $code): CollateralValuation
    {
        return CollateralValuation::tryFrom((string) ($this->meta($code)['valuation'] ?? '')) ?? CollateralValuation::MarketValue;
    }

    public function reference(string $code): CollateralReference
    {
        $default = $this->valuation($code) === CollateralValuation::GoldWeight ? CollateralReference::Custody : CollateralReference::Registration;

        return CollateralReference::tryFrom((string) ($this->meta($code)['reference'] ?? '')) ?? $default;
    }

    /**
     * Validation rules for the details of one type, keyed by field (without the "details." prefix):
     * the type's own fields (whitelisted rules, tokens resolved) plus the fields its valuation needs.
     *
     * @return array<string, list<string>>
     */
    public function fieldRules(string $code): array
    {
        $rules = [];

        foreach ((array) ($this->meta($code)['fields'] ?? []) as $field => $fieldRules) {
            $list = array_values(array_filter((array) $fieldRules, fn ($rule) => is_string($rule) && CustomFields::isAllowedRule($rule)));
            // A field defined without rules is optional free text.
            $rules[(string) $field] = array_map(fn (string $rule) => $this->resolve($rule), $list ?: ['nullable', 'string', 'max:255']);
        }

        return array_merge($rules, $this->valuation($code)->engineFields());
    }

    /**
     * @return list<string> document type slugs that prove a collateral of this type
     */
    public function documents(string $code): array
    {
        return array_values(array_filter((array) ($this->meta($code)['documents'] ?? []), 'is_string'));
    }

    /**
     * Codes of every type valued by gold weight (safekeeping fees and gold renewal read these).
     *
     * @return list<string>
     */
    public function goldCodes(): array
    {
        return array_values(array_map(
            fn (array $value) => $value['code'],
            array_filter($this->lookups->values(LookupType::CollateralType), fn (array $value) => ($value['meta']['valuation'] ?? null) === CollateralValuation::GoldWeight->value)
        ));
    }

    /**
     * @return list<int> lookup ids of every type valued by gold weight
     */
    public function goldIds(): array
    {
        return array_values(array_map(fn (string $code) => (int) $this->lookups->idFor(LookupType::CollateralType, $code), $this->goldCodes()));
    }

    /**
     * Types that can be chosen today, with everything a screen needs to build the form.
     *
     * @return list<array<string, mixed>>
     */
    public function selectable(): array
    {
        $out = [];

        foreach ($this->lookups->values(LookupType::CollateralType) as $value) {
            if (! $value['selectable']) {
                continue;
            }
            $out[] = $this->describe($value['code']);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(string $code): array
    {
        return [
            'code' => $code,
            'label' => $this->label($code),
            'valuation' => $this->valuation($code)->value,
            'reference' => $this->reference($code)->value,
            'fields' => array_map(fn (array $rules) => [
                'rules' => $rules,
                'required' => in_array('required', $rules, true),
            ], $this->fieldRules($code)),
            'documents' => $this->documents($code),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(string $code): array
    {
        return $this->lookups->metaOf(LookupType::CollateralType, $code) ?? [];
    }

    private function resolve(string $rule): string
    {
        [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

        return match ($parameter) {
            'today' => "{$name}:".BusinessDate::today()->toDateString(),
            'next_year' => "{$name}:".(BusinessDate::today()->year + 1),
            default => $rule,
        };
    }
}

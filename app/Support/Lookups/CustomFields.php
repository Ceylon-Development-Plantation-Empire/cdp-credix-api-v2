<?php

declare(strict_types=1);

namespace App\Support\Lookups;

use App\Enums\LookupType;
use App\Models\Lookup;
use Illuminate\Support\Collection;

/**
 * Custom fields stored in the meta JSON column of customers, products and loans, defined as data
 * (lookups of type custom_field.<entity>: code = field key, meta.rules = Laravel rules,
 * label = what the screen shows). A new field never needs a migration or a code change.
 *
 * SECURITY: only rules from a fixed whitelist can be stored on a definition. Rules that read the
 * database (exists, unique), run expressions (regex can be made catastrophically slow) or touch
 * files are refused, so editing a field definition can never become a way to probe data.
 */
final class CustomFields
{
    /**
     * Rule names a custom-field definition may use (with or without ":parameters").
     */
    public const ALLOWED_RULES = [
        'required', 'nullable', 'sometimes', 'string', 'integer', 'numeric', 'boolean', 'array',
        'date', 'date_format', 'email', 'max', 'min', 'between', 'digits', 'digits_between',
        'in', 'gt', 'gte', 'lt', 'lte', 'alpha', 'alpha_num', 'alpha_dash', 'decimal', 'before', 'after', 'before_or_equal', 'after_or_equal',
    ];

    /**
     * Validation rules for the meta object of a request: meta itself plus one entry per active
     * field definition. Keys that are not defined are dropped (validated() only keeps ruled keys).
     *
     * @return array<string, list<string>>
     */
    public static function rules(LookupType $list): array
    {
        $rules = ['meta' => ['sometimes', 'nullable', 'array']];

        foreach (self::definitions($list) as $definition) {
            $rules['meta.'.$definition->code] = array_values(array_filter(
                (array) ($definition->meta['rules'] ?? ['nullable', 'string', 'max:255']),
                fn ($rule) => is_string($rule) && self::isAllowedRule($rule),
            ));
        }

        return $rules;
    }

    /**
     * Whether one rule string may be stored on a custom-field definition.
     */
    public static function isAllowedRule(string $rule): bool
    {
        return in_array(strtolower(explode(':', $rule, 2)[0]), self::ALLOWED_RULES, true);
    }

    /**
     * The validated meta object to store: only defined keys, or null when nothing was sent.
     *
     * @param  array<string, mixed>  $validated  The request's validated() data.
     * @return array<string, mixed>|null
     */
    public static function extract(array $validated): ?array
    {
        $meta = $validated['meta'] ?? null;

        return is_array($meta) && $meta !== [] ? $meta : null;
    }

    /**
     * Active field definitions of a list.
     *
     * @return Collection<int, Lookup>
     */
    private static function definitions(LookupType $list): Collection
    {
        return Lookup::query()->ofType($list)->selectable()->orderBy('sort_order')->get();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\CollateralReference;
use App\Enums\CollateralValuation;
use App\Enums\LookupType;
use App\Exceptions\BusinessRuleException;
use App\Models\Lookup;
use App\Models\User;
use App\Traits\ActivityLogTrait;

/**
 * Adding and changing code-list values. Every change is audited (old -> new) through the
 * senior's ActivityLogTrait; values are never deleted (the model refuses it).
 */
class LookupService
{
    use ActivityLogTrait;

    /**
     * Add a value to a list.
     *
     * @param  array<string, mixed>  $data  Validated StoreLookupRequest data.
     */
    public function create(array $data, User $by): Lookup
    {
        if (($data['type'] ?? null) === LookupType::CollateralType->value) {
            $data['meta'] = $this->collateralMeta($data['meta'] ?? []);
        }

        $lookup = Lookup::create($data + ['sort_order' => 0, 'is_active' => true]);

        $this->logActivity('CREATE', 'Lookup', "Added {$lookup->type->value} value {$lookup->code}", [
            'by' => $by->id,
            'new' => $lookup->only(['type', 'code', 'label', 'meta', 'is_active', 'effective_from', 'effective_to']),
        ]);

        return $lookup;
    }

    /**
     * Relabel, reorder, (de)activate or end-date a value.
     *
     * @param  array<string, mixed>  $data  Validated UpdateLookupRequest data.
     */
    public function update(Lookup $lookup, array $data, User $by): Lookup
    {
        $fields = ['label', 'description', 'meta', 'sort_order', 'is_active', 'effective_from', 'effective_to'];
        $before = $lookup->only($fields);

        if ($lookup->type === LookupType::CollateralType && array_key_exists('meta', $data)) {
            $data['meta'] = $this->collateralMeta($data['meta'] ?? [], $lookup);
        }

        $lookup->update($data);

        $this->logActivity('UPDATE', 'Lookup', "Changed {$lookup->type->value} value {$lookup->code}", [
            'by' => $by->id,
            'old' => $before,
            'new' => $lookup->only($fields),
        ]);

        return $lookup;
    }

    /**
     * The meta of a collateral type with its defaults filled in. For a system type (the engine relies
     * on how it is valued and perfected) the valuation and reference stay as they are.
     *
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>
     */
    protected function collateralMeta(?array $meta, ?Lookup $existing = null): array
    {
        $meta = (array) $meta;

        if ($existing?->is_system) {
            foreach (['valuation', 'reference'] as $key) {
                $current = $existing->meta[$key] ?? null;
                if (isset($meta[$key]) && $meta[$key] !== $current) {
                    throw new BusinessRuleException('SYSTEM_COLLATERAL_TYPE_LOCKED', "The {$key} of a built-in collateral type cannot change; add a new collateral type instead.", 422);
                }
                $meta[$key] = $current;
            }
        }

        $valuation = CollateralValuation::tryFrom((string) ($meta['valuation'] ?? '')) ?? CollateralValuation::MarketValue;

        return [
            'valuation' => $valuation->value,
            'reference' => $meta['reference'] ?? ($valuation === CollateralValuation::GoldWeight ? CollateralReference::Custody : CollateralReference::Registration)->value,
            'fields' => (object) ($meta['fields'] ?? []),
            'documents' => array_values($meta['documents'] ?? []),
            'optional_documents' => array_values(array_diff($meta['optional_documents'] ?? [], $meta['documents'] ?? [])),
        ];
    }
}

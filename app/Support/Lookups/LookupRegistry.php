<?php

declare(strict_types=1);

namespace App\Support\Lookups;

use App\Enums\LookupType;
use App\Models\Lookup;
use App\Support\BusinessDate;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Validation\ValidationException;

/**
 * Cached translation between lookup codes (what the API speaks) and lookup ids (what the foreign
 * keys store).
 *
 * Each list is cached as a whole (lists are short: tens of values) and the cache of a list is
 * dropped whenever one of its values is saved (App\Models\Lookup::booted). Registered as a
 * singleton, so one request reads each list at most once.
 */
final class LookupRegistry
{
    /** @var array<string, array<string, array{id: int, code: string, label: string, meta: array<string, mixed>|null, is_active: bool, from: string|null, to: string|null}>> per-request copy */
    private array $lists = [];

    public function __construct(
        private readonly Cache $cache,
    ) {}

    /**
     * The id stored for a code, for writing a foreign key. Throws a validation error when the code
     * is not a value of the list (the FormRequest normally refuses it first).
     *
     * @throws ValidationException
     */
    public function idFor(LookupType $type, ?string $code, string $field = 'code'): ?int
    {
        if ($code === null || $code === '') {
            return null;
        }

        return $this->list($type)[$code]['id']
            ?? throw ValidationException::withMessages([$field => "The selected {$field} is not valid."]);
    }

    /**
     * The code of a stored id, for responses. Null when the id is null.
     */
    public function codeOf(LookupType $type, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        foreach ($this->list($type) as $code => $value) {
            if ($value['id'] === $id) {
                return (string) $code;
            }
        }

        return null;
    }

    /**
     * The label of a stored id (statements, letters).
     */
    public function labelOf(LookupType $type, ?int $id): ?string
    {
        $code = $this->codeOf($type, $id);

        return $code === null ? null : $this->list($type)[$code]['label'];
    }

    /**
     * Whether a code may be chosen today (active and inside its effective period).
     */
    public function isSelectable(LookupType $type, string $code): bool
    {
        return $this->list($type)[$code]['selectable'] ?? false;
    }

    /**
     * Codes that may be chosen today, in display order.
     *
     * @return list<string>
     */
    public function selectableCodes(LookupType $type): array
    {
        return array_values(array_map(
            fn (array $value) => $value['code'],
            array_filter($this->list($type), fn (array $value) => $value['selectable'])
        ));
    }

    /**
     * The id of the value with this exact label. For lists the API speaks by name (banks: the
     * customer bank-account screens send and show the bank's name).
     *
     * @throws ValidationException
     */
    public function idForLabel(LookupType $type, ?string $label, string $field = 'name'): ?int
    {
        if ($label === null || $label === '') {
            return null;
        }

        foreach ($this->list($type) as $value) {
            if ($value['label'] === $label) {
                return $value['id'];
            }
        }

        throw ValidationException::withMessages([$field => "The selected {$field} is not valid."]);
    }

    /**
     * Labels that may be chosen today, in display order.
     *
     * @return list<string>
     */
    public function selectableLabels(LookupType $type): array
    {
        return array_values(array_map(
            fn (array $value) => $value['label'],
            array_filter($this->list($type), fn (array $value) => $value['selectable'])
        ));
    }

    /**
     * The meta of one value (its settings, e.g. a collateral type's fields), or null when the code
     * is not in the list.
     *
     * @return array<string, mixed>|null
     */
    public function metaOf(LookupType $type, string $code): ?array
    {
        $value = $this->list($type)[$code] ?? null;

        return $value === null ? null : ($value['meta'] ?? []);
    }

    /**
     * Every value of a list (inactive ones included), in display order.
     *
     * @return list<array{id: int, code: string, label: string, meta: array<string, mixed>|null, selectable: bool}>
     */
    public function values(LookupType $type): array
    {
        return array_values($this->list($type));
    }

    /**
     * Drop the cached copy of a list after one of its values changed.
     */
    public function forget(LookupType $type): void
    {
        unset($this->lists[$type->value]);
        $this->cache->forget($this->cacheKey($type));
    }

    /**
     * Every value of a list keyed by code (including inactive ones, so old records keep their code).
     *
     * @return array<string, array{id: int, code: string, label: string, meta: array<string, mixed>|null, selectable: bool}>
     */
    private function list(LookupType $type): array
    {
        // DECISION: "selectable" is worked out on read, not cached, so an effective date passing
        // at midnight takes effect without clearing the cache.
        $values = $this->lists[$type->value] ??= $this->cache->rememberForever($this->cacheKey($type), fn () => Lookup::query()
            ->ofType($type)
            ->orderBy('sort_order')->orderBy('label')
            ->get(['id', 'code', 'label', 'meta', 'is_active', 'effective_from', 'effective_to'])
            ->mapWithKeys(fn (Lookup $lookup) => [$lookup->code => [
                'id' => $lookup->id,
                'code' => $lookup->code,
                'label' => $lookup->label,
                'meta' => $lookup->meta,
                'is_active' => $lookup->is_active,
                'from' => $lookup->effective_from?->toDateString(),
                'to' => $lookup->effective_to?->toDateString(),
            ]])->all());

        $today = BusinessDate::today()->toDateString();

        return array_map(fn (array $value) => [
            'id' => $value['id'],
            'code' => $value['code'],
            'label' => $value['label'],
            'meta' => $value['meta'] ?? null,
            'selectable' => $value['is_active']
                && ($value['from'] === null || $value['from'] <= $today)
                && ($value['to'] === null || $value['to'] >= $today),
        ], $values);
    }

    private function cacheKey(LookupType $type): string
    {
        // v2: the cached shape includes meta (collateral types read their fields and documents from it).
        return "lookups:v2:{$type->value}";
    }
}

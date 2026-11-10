<?php

declare(strict_types=1);

namespace App\Support\Charges;

use App\Enums\ChargeBehaviour;
use App\Models\ChargeType;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Cached access to the charge types: code ↔ id for the API and foreign keys, and the system type
 * of each engine behaviour (the one late-payment / early-settlement / safekeeping type).
 *
 * The whole table is cached (a few dozen rows) and dropped whenever a type is saved.
 */
final class ChargeTypeRegistry
{
    private const CACHE_KEY = 'charge_types:all';

    /** @var Collection<string, ChargeType>|null per-request copy keyed by code */
    private ?Collection $types = null;

    public function __construct(
        private readonly Cache $cache,
    ) {}

    /**
     * A type by code, or null when there is none.
     */
    public function find(?string $code): ?ChargeType
    {
        return $code === null ? null : $this->all()->get($code);
    }

    /**
     * A type by id, or null.
     */
    public function findById(?int $id): ?ChargeType
    {
        return $id === null ? null : $this->all()->first(fn (ChargeType $type) => $type->id === $id);
    }

    /**
     * The system type the engine raises for a behaviour.
     *
     * @throws LogicException when the system rows were never seeded (ChargeTypeSeeder).
     */
    public function system(ChargeBehaviour $behaviour): ChargeType
    {
        return $this->all()->first(fn (ChargeType $type) => $type->is_system && $type->behaviour === $behaviour)
            ?? throw new LogicException("No system charge type for {$behaviour->value}; run the ChargeTypeSeeder.");
    }

    /**
     * Every type keyed by code, in display order.
     *
     * @return Collection<string, ChargeType>
     */
    public function all(): Collection
    {
        return $this->types ??= collect($this->cache->rememberForever(self::CACHE_KEY, fn () => ChargeType::query()
            ->orderBy('sort_order')->orderBy('name')->get()->all()))
            ->keyBy('code');
    }

    /**
     * Drop the cache after a type changed.
     */
    public function forget(): void
    {
        $this->types = null;
        $this->cache->forget(self::CACHE_KEY);
    }
}

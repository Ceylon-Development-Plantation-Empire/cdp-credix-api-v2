<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ChargeBehaviour;
use App\Models\ChargeType;
use App\Models\User;
use App\Traits\ActivityLogTrait;

/**
 * Creating and changing fee types. Every change is audited (old -> new).
 */
class ChargeTypeService
{
    use ActivityLogTrait;

    /**
     * Fields shown in the audit trail.
     */
    private const AUDITED = ['code', 'name', 'display_group', 'behaviour', 'allowed_calculations', 'allowed_recurrences', 'is_active', 'sort_order'];

    /**
     * A new (always upfront) fee type.
     *
     * @param  array<string, mixed>  $data  Validated StoreChargeTypeRequest data.
     */
    public function create(array $data, User $by): ChargeType
    {
        $type = ChargeType::create($data + [
            'behaviour' => ChargeBehaviour::Upfront,
            'is_active' => true,
            'updated_by' => $by->id,
        ]);

        $this->logActivity('CREATE', 'ChargeType', "Added fee type {$type->code}", ['new' => $type->only(self::AUDITED)]);

        return $type;
    }

    /**
     * Rename, regroup, reorder or narrow a fee type.
     *
     * @param  array<string, mixed>  $data  Validated UpdateChargeTypeRequest data.
     */
    public function update(ChargeType $type, array $data, User $by): ChargeType
    {
        $before = $type->only(self::AUDITED);

        $type->update($data + ['updated_by' => $by->id]);

        $this->logActivity('UPDATE', 'ChargeType', "Changed fee type {$type->code}", ['old' => $before, 'new' => $type->only(self::AUDITED)]);

        return $type;
    }
}

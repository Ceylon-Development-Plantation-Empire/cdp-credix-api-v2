<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Enums\ChargeCollection;
use App\Models\ChargeType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A fee type, with what a product may configure for it.
 *
 * @mixin ChargeType
 */
class ChargeTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'display_group' => $this->display_group,
            'behaviour' => $this->behaviour->value,
            'calculations' => $this->allowed_calculations,
            'collections' => array_map(fn (ChargeCollection $c) => $c->value, $this->behaviour->allowedCollections()),
            'recurrences' => $this->allowed_recurrences,
            'description' => $this->description,
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}

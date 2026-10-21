<?php

namespace App\Enums;

/**
 * Product life cycle (design section 2.1).
 *
 *   draft → active                          conventional product
 *   draft → pending_shariah → active        Islamic product, after Shariah board approval
 *   pending_shariah → draft                 Shariah board rejected it
 *   active ↔ inactive                       stop / restart selling
 */
enum ProductStatus: string
{
    case Draft = 'draft';
    case PendingShariah = 'pending_shariah';
    case Active = 'active';
    case Inactive = 'inactive';

    /**
     * Only active products can be used for new applications.
     */
    public function isSellable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Terms (rates, charges, limits …) can be edited freely only while the product has never gone live.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}

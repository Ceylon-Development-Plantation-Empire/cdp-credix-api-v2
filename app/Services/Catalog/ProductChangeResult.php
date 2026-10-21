<?php

namespace App\Services\Catalog;

use App\Models\ApprovalRequest;
use App\Models\Product;

/**
 * Result of a product change: the product as it is now, and the approval request when part of
 * the change has to wait for Shariah approval (the controller then answers 202 Accepted).
 */
final class ProductChangeResult
{
    public function __construct(
        public readonly Product $product,
        public readonly ?ApprovalRequest $pendingApproval,
    ) {}

    public function needsApproval(): bool
    {
        return $this->pendingApproval !== null;
    }
}

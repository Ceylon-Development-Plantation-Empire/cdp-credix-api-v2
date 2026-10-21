<?php

namespace App\Services\Catalog\Approvals;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ApprovalRequest;
use App\Models\Product;
use App\Models\User;
use App\Services\Approvals\Contracts\ApprovalHandler;

/**
 * Shariah board decision on a new Islamic product.
 *
 * Approved → the product goes live with the board's reference recorded on it.
 * Rejected (or withdrawn) → back to draft so the terms can be corrected and resubmitted.
 */
class ProductShariahApprovalHandler implements ApprovalHandler
{
    public function approved(ApprovalRequest $request, User $approver): void
    {
        $product = $this->lockedProduct($request);

        $product->forceFill([
            'status' => ProductStatus::Active,
            'shariah_approved_at' => now(),
            'shariah_reference' => $request->decision_reference,
            'updated_by' => $approver->id,
        ])->save();
    }

    public function rejected(ApprovalRequest $request, User $approver): void
    {
        $product = $this->lockedProduct($request);

        $product->forceFill(['status' => ProductStatus::Draft, 'updated_by' => $approver->id])->save();
    }

    protected function lockedProduct(ApprovalRequest $request): Product
    {
        $product = Product::whereKey($request->subject_id)->lockForUpdate()->firstOrFail();

        if ($product->status !== ProductStatus::PendingShariah) {
            throw new BusinessRuleException('PRODUCT_NOT_UNDER_REVIEW', 'The product is no longer waiting for Shariah review.');
        }

        return $product;
    }
}

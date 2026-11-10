<?php

declare(strict_types=1);

namespace App\Services\Catalog\Approvals;

use App\Models\ApprovalRequest;
use App\Models\Product;
use App\Models\User;
use App\Services\Approvals\Contracts\ApprovalHandler;
use App\Services\Catalog\ProductService;

/**
 * Shariah board decision on a change to an approved Islamic product.
 * Until it is approved the product keeps selling on its old, approved terms.
 */
class ProductTermsChangeHandler implements ApprovalHandler
{
    public function __construct(
        protected ProductService $products,
    ) {}

    public function approved(ApprovalRequest $request, User $approver): void
    {
        $product = Product::whereKey($request->subject_id)->lockForUpdate()->firstOrFail();

        $this->products->applyApprovedTermsChange($product, $request->payload, $request->decision_reference);
        $product->forceFill(['updated_by' => $approver->id])->save();
    }

    public function rejected(ApprovalRequest $request, User $approver): void
    {
        // Nothing to undo: the proposed terms were never applied.
    }
}

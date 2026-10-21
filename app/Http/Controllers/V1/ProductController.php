<?php

namespace App\Http\Controllers\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CreateProductRequest;
use App\Http\Requests\Catalog\QuoteProductRequest;
use App\Http\Requests\Catalog\ReplaceCollateralRulesRequest;
use App\Http\Requests\Catalog\ReplaceProductChargesRequest;
use App\Http\Requests\Catalog\ShariahReviewRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Resources\Approvals\ApprovalRequestResource;
use App\Http\Resources\Catalog\ProductChargeResource;
use App\Http\Resources\Catalog\ProductCollateralRuleResource;
use App\Http\Resources\Catalog\ProductListResource;
use App\Http\Resources\Catalog\ProductResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Catalog\ProductChangeResult;
use App\Services\Catalog\ProductService;
use App\Traits\ActivityLogTrait;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ProductController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, HandlesApiErrors, PaginatesResources;

    /**
     * Relations every detail response needs, loaded in one go.
     */
    protected const DETAIL_RELATIONS = ['category', 'counterpart', 'charges', 'collateralRules'];

    public function __construct(
        protected ProductService $products,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Product Index', only: ['index', 'show', 'options', 'charges', 'collateralRules', 'quote']),
            new Middleware('permission:Product Create', only: ['store']),
            new Middleware('permission:Product Update', only: ['update', 'replaceCharges', 'replaceCollateralRules']),
            new Middleware('permission:Product Delete', only: ['destroy']),
            new Middleware('permission:Product Toggle Status', only: ['toggleStatus']),
            new Middleware('permission:Product Shariah Submit', only: ['submitForShariahReview']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $query = Product::with('category')->orderBy('name');

            if ($request->filled('search')) {
                $query->search($request->search);
            }

            // A category filter includes every sub-category under it.
            if ($request->filled('category_slug')) {
                $category = ProductCategory::findBySlug($request->category_slug);
                $query->whereIn('category_id', $category ? $category->descendantIdsWithSelf() : [0]);
            }

            if ($request->filled('finance_basis')) {
                $query->whereHas('category', fn ($q) => $q->where('finance_basis', $request->finance_basis));
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('pricing_method')) {
                $query->where('pricing_method', $request->pricing_method);
            }

            if ($request->filled('borrower_type') && in_array($request->borrower_type, ['individual', 'joint', 'group'], true)) {
                $query->where("allows_{$request->borrower_type}", true);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Products retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), ProductListResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve products', $th);
        }
    }

    /**
     * Valid choices for the product form (pricing methods per basis, charge options …).
     */
    public function options()
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Product options retrieved successfully',
            'data' => ProductService::formOptions(),
        ], 200);
    }

    public function store(CreateProductRequest $request)
    {
        try {
            $data = $request->validated();
            $product = $this->products->create($data, $request->user());

            $this->logActivity('CREATE', 'Product', "Created product: {$product->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Product created as a draft',
                'data' => new ProductResource($product->load(self::DETAIL_RELATIONS)),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to create product', $th);
        }
    }

    public function show(string $slug)
    {
        try {
            $product = Product::with(self::DETAIL_RELATIONS)->where('slug', $slug)->first();

            if (! $product) {
                return $this->notFound('Product not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Product retrieved successfully',
                'data' => new ProductResource($product),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve product', $th);
        }
    }

    public function update(UpdateProductRequest $request, string $slug)
    {
        try {
            $product = Product::findBySlug($slug);

            if (! $product) {
                return $this->notFound('Product not found');
            }

            $data = $request->validated();
            $result = $this->products->update($product, $data, $request->user());

            $this->logActivity('UPDATE', 'Product', "Updated product: {$result->product->name}", $data);

            return $this->changeResponse($result, 'Product updated successfully');
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update product', $th);
        }
    }

    public function destroy(string $slug)
    {
        try {
            $product = Product::findBySlug($slug);

            if (! $product) {
                return $this->notFound('Product not found');
            }

            $this->products->delete($product);

            $this->logActivity('DELETE', 'Product', "Deleted draft product: {$product->name}");

            return response()->json([
                'status' => 'success',
                'message' => 'Product deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to delete product', $th);
        }
    }

    /**
     * Active → inactive, or draft/inactive → active (Islamic products only after Shariah approval).
     */
    public function toggleStatus(Request $request, string $slug)
    {
        try {
            $product = Product::findBySlug($slug);

            if (! $product) {
                return $this->notFound('Product not found');
            }

            $product = $product->status === ProductStatus::Active
                ? $this->products->deactivate($product, $request->user())
                : $this->products->activate($product, $request->user());

            $this->logActivity('TOGGLE_STATUS', 'Product', "Product {$product->name} is now {$product->status->value}");

            return response()->json([
                'status' => 'success',
                'message' => 'Product status updated successfully',
                'data' => [
                    'slug' => $product->slug,
                    'status' => $product->status->value,
                ],
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to change product status', $th);
        }
    }

    public function submitForShariahReview(ShariahReviewRequest $request, string $slug)
    {
        try {
            $product = Product::findBySlug($slug);

            if (! $product) {
                return $this->notFound('Product not found');
            }

            $approval = $this->products->submitForShariahReview($product, $request->validated('reason'), $request->user());

            $this->logActivity('SHARIAH_SUBMIT', 'Product', "Submitted product for Shariah review: {$product->name}");

            return response()->json([
                'status' => 'success',
                'message' => 'Product submitted for Shariah review',
                'data' => new ApprovalRequestResource($approval->load('requester')),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to submit product for Shariah review', $th);
        }
    }

    public function charges(string $slug)
    {
        try {
            $product = Product::findBySlug($slug);

            if (! $product) {
                return $this->notFound('Product not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Product charges retrieved successfully',
                'data' => ProductChargeResource::collection($product->charges),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve product charges', $th);
        }
    }

    public function replaceCharges(ReplaceProductChargesRequest $request, string $slug)
    {
        try {
            $product = Product::findBySlug($slug);

            if (! $product) {
                return $this->notFound('Product not found');
            }

            $data = $request->validated();
            $result = $this->products->replaceCharges($product, $data['charges'], $data['change_reason'] ?? null, $request->user());

            $this->logActivity('UPDATE_CHARGES', 'Product', "Updated charges of product: {$product->name}", $data);

            return $this->changeResponse($result, 'Product charges updated successfully');
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update product charges', $th);
        }
    }

    public function collateralRules(string $slug)
    {
        try {
            $product = Product::findBySlug($slug);

            if (! $product) {
                return $this->notFound('Product not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Product collateral rules retrieved successfully',
                'data' => ProductCollateralRuleResource::collection($product->collateralRules),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve product collateral rules', $th);
        }
    }

    public function replaceCollateralRules(ReplaceCollateralRulesRequest $request, string $slug)
    {
        try {
            $product = Product::findBySlug($slug);

            if (! $product) {
                return $this->notFound('Product not found');
            }

            $data = $request->validated();
            $product = $this->products->replaceCollateralRules($product, $data['collateral_rules'], $request->user());

            $this->logActivity('UPDATE_COLLATERAL_RULES', 'Product', "Updated collateral rules of product: {$product->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Product collateral rules updated successfully',
                'data' => ProductCollateralRuleResource::collection($product->collateralRules),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update product collateral rules', $th);
        }
    }

    /**
     * Schedule preview. Nothing is saved, so it is safe to call on every keystroke of the loan form.
     */
    public function quote(QuoteProductRequest $request, string $slug)
    {
        try {
            $product = Product::with('category')->where('slug', $slug)->first();

            if (! $product) {
                return $this->notFound('Product not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Quote calculated successfully',
                'data' => $this->products->quote($product, $request->validated()),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to calculate quote', $th);
        }
    }

    /**
     * 200 when the change is applied, 202 when (part of) it waits for Shariah approval.
     */
    protected function changeResponse(ProductChangeResult $result, string $message)
    {
        $product = $result->product->load(self::DETAIL_RELATIONS);

        if ($result->needsApproval()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Changes to Shariah-approved terms were sent for Shariah approval; other changes were applied',
                'data' => [
                    'product' => new ProductResource($product),
                    'approval_request' => new ApprovalRequestResource($result->pendingApproval),
                ],
            ], 202);
        }

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => new ProductResource($product),
        ], 200);
    }
}

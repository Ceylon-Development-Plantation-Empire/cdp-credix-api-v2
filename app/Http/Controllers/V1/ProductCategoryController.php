<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CreateProductCategoryRequest;
use App\Http\Requests\Catalog\UpdateProductCategoryRequest;
use App\Http\Resources\Catalog\ProductCategoryResource;
use App\Models\ProductCategory;
use App\Services\Catalog\ProductCategoryService;
use App\Traits\ActivityLogTrait;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ProductCategoryController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, HandlesApiErrors, PaginatesResources;

    public function __construct(
        protected ProductCategoryService $categories,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Product Category Index', only: ['index', 'show', 'tree']),
            new Middleware('permission:Product Category Create', only: ['store']),
            new Middleware('permission:Product Category Update', only: ['update']),
            new Middleware('permission:Product Category Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $query = ProductCategory::with('parent')->withCount('products')->orderBy('sort_order')->orderBy('name');

            if ($request->filled('search')) {
                $query->search($request->search);
            }

            if ($request->filled('finance_basis')) {
                $query->where('finance_basis', $request->finance_basis);
            }

            if ($request->filled('parent_slug')) {
                $query->whereHas('parent', fn ($q) => $q->where('slug', $request->parent_slug));
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Product categories retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), ProductCategoryResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve product categories', $th);
        }
    }

    /**
     * The whole tree (General / Islamic → … ) for menus and the product form.
     * ?active_only=1 hides inactive branches.
     */
    public function tree(Request $request)
    {
        try {
            $activeOnly = $request->boolean('active_only');
            $all = ProductCategory::query()
                ->when($activeOnly, fn ($q) => $q->active())
                ->orderBy('sort_order')->orderBy('name')
                ->get();

            // Build the tree in memory from one query instead of one query per level.
            $byParent = $all->groupBy('parent_id');
            $attach = function (ProductCategory $category) use (&$attach, $byParent) {
                $children = $byParent->get($category->id, collect())->each($attach);
                $category->setRelation('children', $children->values());
            };

            $roots = $byParent->get(null, collect())->each($attach)->values();

            return response()->json([
                'status' => 'success',
                'message' => 'Product category tree retrieved successfully',
                'data' => ProductCategoryResource::collection($roots),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve product category tree', $th);
        }
    }

    public function store(CreateProductCategoryRequest $request)
    {
        try {
            $data = $request->validated();
            $category = $this->categories->create($data, $request->user());

            $this->logActivity('CREATE', 'ProductCategory', "Created product category: {$category->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Product category created successfully',
                'data' => new ProductCategoryResource($category->load('parent')),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to create product category', $th);
        }
    }

    public function show(string $slug)
    {
        try {
            $category = ProductCategory::with(['parent', 'children'])->withCount('products')->where('slug', $slug)->first();

            if (! $category) {
                return $this->notFound('Product category not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Product category retrieved successfully',
                'data' => new ProductCategoryResource($category),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve product category', $th);
        }
    }

    public function update(UpdateProductCategoryRequest $request, string $slug)
    {
        try {
            $category = ProductCategory::findBySlug($slug);

            if (! $category) {
                return $this->notFound('Product category not found');
            }

            $data = $request->validated();
            $category = $this->categories->update($category, $data, $request->user());

            $this->logActivity('UPDATE', 'ProductCategory', "Updated product category: {$category->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Product category updated successfully',
                'data' => new ProductCategoryResource($category),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update product category', $th);
        }
    }

    /**
     * Categories are never deleted (products and loans point at them); they are switched off instead.
     * An inactive category's products cannot be activated or sold.
     */
    public function toggleStatus(string $slug)
    {
        try {
            $category = ProductCategory::findBySlug($slug);

            if (! $category) {
                return $this->notFound('Product category not found');
            }

            $category->update(['is_active' => ! $category->is_active, 'updated_by' => auth('api')->id()]);

            $this->logActivity('TOGGLE_STATUS', 'ProductCategory', "Toggled product category status: {$category->name} (".($category->is_active ? 'Active' : 'Inactive').')');

            return response()->json([
                'status' => 'success',
                'message' => 'Product category status updated successfully',
                'data' => [
                    'slug' => $category->slug,
                    'is_active' => $category->is_active,
                ],
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to toggle product category status', $th);
        }
    }
}

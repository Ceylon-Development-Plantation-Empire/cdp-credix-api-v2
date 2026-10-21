<?php

namespace App\Services\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProductCategoryService
{
    /**
     * Create a category.
     *
     * A root category (no parent) must say whether it is conventional or Islamic. A child always takes
     * its parent's basis, whatever was sent, so the tree can never mix the two worlds.
     *
     * @param  array<string, mixed>  $data  Validated request data (parent given as parent_slug).
     */
    public function create(array $data, User $user): ProductCategory
    {
        $parent = isset($data['parent_slug']) ? ProductCategory::findBySlug($data['parent_slug']) : null;

        $attributes = Arr::except($data, ['parent_slug']);
        $attributes['parent_id'] = $parent?->id;
        $attributes['created_by'] = $user->id;
        $attributes['updated_by'] = $user->id;

        if ($parent) {
            $attributes['finance_basis'] = $parent->finance_basis;
        }

        return ProductCategory::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProductCategory $category, array $data, User $user): ProductCategory
    {
        return DB::transaction(function () use ($category, $data, $user) {
            $attributes = Arr::except($data, ['parent_slug', 'finance_basis']);

            if (array_key_exists('parent_slug', $data)) {
                $attributes['parent_id'] = $this->resolveNewParent($category, $data['parent_slug'])?->id;
            }

            // The basis is fixed once the category exists: moving a category from General to Islamic
            // would silently change how every product under it is priced.
            if (isset($data['finance_basis']) && $data['finance_basis'] !== $category->finance_basis->value) {
                throw new BusinessRuleException('CATEGORY_BASIS_IMMUTABLE', 'The finance basis of a category cannot be changed.', 422);
            }

            // The slug is part of URLs and seeded data; it can only change while nothing uses the category.
            if (isset($data['slug']) && $data['slug'] !== $category->slug && $category->products()->withTrashed()->exists()) {
                throw new BusinessRuleException('CATEGORY_SLUG_LOCKED', 'The slug cannot be changed because products use this category.', 422);
            }

            $attributes['updated_by'] = $user->id;
            $category->update($attributes);

            return $category->fresh(['parent']);
        });
    }

    /**
     * A category can move under another parent of the same basis, but never under itself or
     * one of its own children (that would make a loop in the tree).
     */
    protected function resolveNewParent(ProductCategory $category, ?string $parentSlug): ?ProductCategory
    {
        if ($parentSlug === null) {
            if (! $category->isRoot()) {
                throw new BusinessRuleException('CATEGORY_CANNOT_BECOME_ROOT', 'A sub-category cannot become a root category.', 422);
            }

            return null;
        }

        $parent = ProductCategory::findBySlug($parentSlug);

        if (in_array($parent->id, $category->descendantIdsWithSelf(), true)) {
            throw new BusinessRuleException('CATEGORY_LOOP', 'A category cannot be moved under itself or one of its sub-categories.', 422);
        }

        if ($parent->finance_basis !== $category->finance_basis) {
            throw new BusinessRuleException('CATEGORY_BASIS_MISMATCH', 'A category can only be moved under a parent with the same finance basis.', 422);
        }

        return $parent;
    }
}

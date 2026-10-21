<?php

namespace App\Http\Resources\Catalog;

use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductCategory
 */
class ProductCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'finance_basis' => $this->finance_basis->value,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'slug' => $this->parent->slug,
                'name' => $this->parent->name,
            ] : null),
            // Recursive: used by the tree endpoint, where children are loaded level by level.
            'children' => self::collection($this->whenLoaded('children')),
            'products_count' => $this->whenCounted('products'),
        ];
    }
}

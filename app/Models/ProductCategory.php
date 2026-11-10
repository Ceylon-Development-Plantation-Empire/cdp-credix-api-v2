<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FinanceBasis;
use App\Models\Concerns\Audited;
use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    use Audited;
    use HasSlug;

    protected $fillable = [
        'parent_id',
        'slug',
        'name',
        'finance_basis',
        'description',
        'sort_order',
        'is_active',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'finance_basis' => FinanceBasis::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%");
        });
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Ids of this category and everything below it. Used to stop a category being moved
     * under its own child (which would create a loop) and to list all products of a branch of the tree.
     *
     * The tree is small (tens of rows), so loading it once is cheaper and simpler than a recursive query.
     *
     * @return list<int>
     */
    public function descendantIdsWithSelf(): array
    {
        $childrenByParent = static::query()->get(['id', 'parent_id'])->groupBy('parent_id');
        $ids = [];
        $stack = [$this->id];

        while ($stack) {
            $id = array_pop($stack);
            $ids[] = $id;

            foreach ($childrenByParent->get($id, collect()) as $child) {
                $stack[] = $child->id;
            }
        }

        return $ids;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}

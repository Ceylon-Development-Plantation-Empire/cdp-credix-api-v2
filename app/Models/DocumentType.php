<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentTarget;
use App\Models\Concerns\Audited;
use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    use Audited;
    use HasSlug;

    protected $fillable = [
        'slug',
        'name',
        'target',
        'validity_days',
        'allowed_mimes',
        'max_size_kb',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target' => DocumentTarget::class,
            'validity_days' => 'integer',
            'max_size_kb' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(DocumentRequirement::class);
    }
}

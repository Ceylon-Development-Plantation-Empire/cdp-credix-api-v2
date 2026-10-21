<?php

namespace App\Models;

use App\Enums\Language;
use App\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends Model
{
    protected $fillable = [
        'slug',
        'channel',
        'language',
        'product_id',
        'category_id',
        'subject',
        'body',
        'is_active',
        'updated_by',
    ];

    protected $casts = [
        'channel' => NotificationChannel::class,
        'language' => Language::class,
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }
}

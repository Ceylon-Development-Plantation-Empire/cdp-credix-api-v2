<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LookupType;
use App\Models\Concerns\Audited;
use App\Support\Lookups\LookupAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A job title within a department.
 *
 * level is a lookup code (designation_level); when no order_weight is given it is taken from the
 * level's meta.weight, so seniority weights are data instead of a hard-coded table.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $level
 * @property int $order_weight
 */
class Designation extends Model
{
    use Audited;
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'department_id',
        'level',
        'order_weight',
        'description',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['level_id'];

    /**
     * @var list<string>
     */
    protected $appends = ['level'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'order_weight' => 'integer',
        ];
    }

    /**
     * Default the seniority weight from the level lookup (meta.weight) when none was given.
     */
    protected static function booted(): void
    {
        static::saving(function (Designation $designation) {
            if (empty($designation->order_weight) && $designation->level_id !== null) {
                $weight = Lookup::query()->whereKey($designation->level_id)->first()?->meta['weight'] ?? null;
                $designation->order_weight = (int) ($weight ?? 0);
            }
        });
    }

    /**
     * level (code) ↔ level_id (lookups FK).
     *
     * @return Attribute<string|null, string|null>
     */
    protected function level(): Attribute
    {
        return LookupAttribute::for($this, LookupType::DesignationLevel, 'level_id', 'level');
    }

    /**
     * @param  Builder<Designation>  $query
     * @return Builder<Designation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Search by name or code.
     *
     * @param  Builder<Designation>  $query
     * @return Builder<Designation>
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%");
        });
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}

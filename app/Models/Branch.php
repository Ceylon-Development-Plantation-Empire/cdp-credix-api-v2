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
 * A branch: the unit every customer, loan, recovery case and journal belongs to.
 *
 * branch_type is a lookup code (main, city, satellite, mobile …) stored as branch_type_id.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $branch_type
 * @property bool $is_active
 * @property bool $is_head_office
 */
class Branch extends Model
{
    use Audited;
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'group_id',
        'address_line1',
        'address_line2',
        'city',
        'postal_code',
        'zone_id',
        'region_id',
        'province_id',
        'phone_primary',
        'phone_secondary',
        'email',
        'fax',
        'opening_date',
        'branch_type',
        'latitude',
        'longitude',
        'is_active',
        'is_head_office',
    ];

    /**
     * The API shows the lookup code, not its id.
     *
     * @var list<string>
     */
    protected $hidden = ['branch_type_id'];

    /**
     * @var list<string>
     */
    protected $appends = ['branch_type'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_head_office' => 'boolean',
            'opening_date' => 'date',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
        ];
    }

    /**
     * branch_type (code) ↔ branch_type_id (lookups FK).
     *
     * @return Attribute<string|null, string|null>
     */
    protected function branchType(): Attribute
    {
        return LookupAttribute::for($this, LookupType::BranchType, 'branch_type_id', 'branch_type');
    }

    /**
     * Only active branches.
     *
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Search by name, code or city.
     *
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%");
        });
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /**
     * @return BelongsTo<Zonal, $this>
     */
    public function zonal(): BelongsTo
    {
        return $this->belongsTo(Zonal::class, 'zone_id');
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}

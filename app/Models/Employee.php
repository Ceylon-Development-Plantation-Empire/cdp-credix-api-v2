<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LookupType;
use App\Models\Concerns\Audited;
use App\Support\BlindIndex;
use App\Support\Lookups\LookupAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * The HR record behind a staff user.
 *
 * SECURITY: id_number is encrypted at rest; id_number_hash (blind index of the trimmed, upper-cased
 * number) is kept in step on every save and is what uniqueness and look-ups use.
 *
 * @property int $id
 * @property string $full_name
 * @property string|null $employee_type
 * @property string $id_number
 * @property string $id_number_hash
 * @property bool $is_active
 */
class Employee extends Model
{
    use Audited;
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'f_name',
        'l_name',
        'full_name',
        'name_with_initials',
        'employee_code',
        'reporting_manager_id',
        'province_id',
        'region_id',
        'zonal_id',
        'branch_id',
        'department_id',
        'designation_id',
        'employee_type',
        'id_type',
        'id_number',
        'date_of_birth',
        'email',
        'phone',
        'address_line_1',
        'city',
        'state',
        'country',
        'postal_code',
        'phone_primary',
        'phone_secondary',
        'have_whatsapp',
        'whatsapp_number',
        'start_date',
        'end_date',
        'is_active',
    ];

    /**
     * SECURITY: the blind index and the lookup id never leave the API.
     *
     * @var list<string>
     */
    protected $hidden = ['id_number_hash', 'employee_type_id'];

    /**
     * @var list<string>
     */
    protected $appends = ['employee_type'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // SECURITY: AES-256 with APP_KEY at rest; decrypted only when read in PHP.
            'id_number' => 'encrypted',
            'is_active' => 'boolean',
            'have_whatsapp' => 'boolean',
            'date_of_birth' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * Keep the blind index in step with the encrypted ID number.
     */
    protected static function booted(): void
    {
        static::saving(function (Employee $employee) {
            if ($employee->isDirty('id_number') || $employee->id_number_hash === null) {
                $employee->id_number_hash = self::idNumberHash((string) $employee->id_number);
            }
        });
    }

    /**
     * The blind index of an employee ID number (spaces removed, upper case).
     */
    public static function idNumberHash(string $idNumber): string
    {
        return BlindIndex::hash('employee-id', strtoupper((string) preg_replace('/\s+/', '', $idNumber)));
    }

    /**
     * Find an employee by ID number without decrypting every row.
     */
    public static function findByIdNumber(string $idNumber): ?self
    {
        return static::query()->where('id_number_hash', self::idNumberHash($idNumber))->first();
    }

    /**
     * employee_type (code) ↔ employee_type_id (lookups FK).
     *
     * @return Attribute<string|null, string|null>
     */
    protected function employeeType(): Attribute
    {
        return LookupAttribute::for($this, LookupType::EmployeeType, 'employee_type_id', 'employee_type');
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Search by name, email or employee code (the ID number is encrypted and never LIKE-searched).
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->where('f_name', 'like', "%{$search}%")
                ->orWhere('l_name', 'like', "%{$search}%")
                ->orWhere('full_name', 'like', "%{$search}%")
                ->orWhere('employee_code', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('id_number_hash', self::idNumberHash($search));
        });
    }

    /**
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function reportingManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reporting_manager_id');
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'reporting_manager_id');
    }

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * @return BelongsTo<Zonal, $this>
     */
    public function zonal(): BelongsTo
    {
        return $this->belongsTo(Zonal::class, 'zonal_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /**
     * @return HasMany<EmployeeHierarchyHistory, $this>
     */
    public function hierarchyHistories(): HasMany
    {
        return $this->hasMany(EmployeeHierarchyHistory::class, 'employee_id');
    }

    /**
     * The reporting manager on a given date (from the hierarchy history).
     *
     * @param  string  $date  Y-m-d
     */
    public function resolveReportingManagerAsOf(string $date): ?Employee
    {
        return EmployeeHierarchyHistory::where('employee_id', $this->id)
            ->asOf($date)
            ->with('reportingManager')
            ->first()
            ?->reportingManager;
    }

    /**
     * The direct subordinates on a given date (from the hierarchy history).
     *
     * @param  string  $date  Y-m-d
     * @return Collection<int, Employee>
     */
    public function resolveSubordinatesAsOf(string $date): Collection
    {
        return EmployeeHierarchyHistory::where('reporting_manager_id', $this->id)
            ->asOf($date)
            ->with('employee')
            ->get()
            ->map(fn (EmployeeHierarchyHistory $history) => $history->employee)
            ->filter()
            ->values();
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserType;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

/**
 * A login of the back office (admin, staff) or of an external recovery agency.
 *
 * Staff and admins sign in with their email and password (AuthController). Users are never deleted:
 * they are deactivated, so the audit trail always names a real person.
 *
 * SECURITY: every token carries the user's token_version ("tv" claim). Raising the version
 * (password change, logout everywhere, deactivation) invalidates every earlier token at once;
 * EnsureTokenIsCurrent checks it on each request.
 *
 * @property int $id
 * @property string $name
 * @property string|null $username
 * @property string $email
 * @property string $user_type
 * @property int|null $employee_id
 * @property bool $is_active
 * @property bool $can_login
 * @property bool $must_change_password
 * @property int $token_version
 * @property int $failed_login_attempts
 * @property Carbon|null $locked_until
 */
class User extends Authenticatable implements JWTSubject
{
    use Audited;

    /**
     * Columns whose change alone is not written to the audit trail (see Concerns\Audited).
     */
    public const AUDIT_IGNORED = ['last_login_at', 'last_login_ip', 'failed_login_attempts', 'remember_token'];

    use HasFactory, HasRoles, Notifiable;

    /**
     * Security columns (token_version, failed_login_attempts, locked_until, password_changed_at,
     * last_login_*) are not mass assignable; only the auth services set them.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
        'password',
        'user_type',
        'employee_id',
        'is_active',
        'can_login',
        'must_change_password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'token_version',
        'failed_login_attempts',
        'locked_until',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'is_active' => 'boolean',
            'can_login' => 'boolean',
            'must_change_password' => 'boolean',
            'token_version' => 'integer',
            'failed_login_attempts' => 'integer',
        ];
    }

    /**
     * JWT subject: the user id.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Extra JWT claims.
     *
     * SECURITY: only the token version. Name, email and username were removed: a JWT payload is
     * readable by anyone holding the token, and the frontend reads the user from GET /me.
     *
     * @return array<string, int>
     */
    public function getJWTCustomClaims(): array
    {
        return ['tv' => (int) $this->token_version];
    }

    /**
     * Back-office users only (admin, staff).
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeBackOffice(Builder $query): Builder
    {
        return $query->whereIn('user_type', UserType::backOffice());
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The branch of the user's employee record (null for head office and agencies).
     *
     * @return Attribute<Branch|null, never>
     */
    protected function branch(): Attribute
    {
        return Attribute::get(fn () => $this->employee?->branch);
    }

    /**
     * @return Attribute<Zonal|null, never>
     */
    protected function zone(): Attribute
    {
        return Attribute::get(fn () => $this->employee?->zonal);
    }

    /**
     * @return Attribute<Region|null, never>
     */
    protected function region(): Attribute
    {
        return Attribute::get(fn () => $this->employee?->region);
    }

    /**
     * @return Attribute<Province|null, never>
     */
    protected function province(): Attribute
    {
        return Attribute::get(fn () => $this->employee?->province);
    }

    /**
     * The user of the employee's reporting manager.
     *
     * @return Attribute<User|null, never>
     */
    protected function parent(): Attribute
    {
        return Attribute::get(fn () => $this->employee?->reportingManager?->user);
    }

    /**
     * Users of the employee's direct reports.
     *
     * @return Attribute<Collection<int, User>, never>
     */
    protected function children(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->employee_id) {
                return collect();
            }

            $subordinateIds = Employee::where('reporting_manager_id', $this->employee_id)->pluck('id');

            return User::whereIn('employee_id', $subordinateIds)->get();
        });
    }

    /**
     * The starter-kit user shape: branch, zone, region, province, parent and children are always
     * present (null / empty unless the employee relations were loaded), so screens never read an
     * undefined key.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        $employee = $this->relationLoaded('employee') ? $this->employee : null;

        $array['branch'] = $employee?->relationLoaded('branch') ? $employee->branch : null;
        $array['zone'] = $employee?->relationLoaded('zonal') ? $employee->zonal : null;
        $array['region'] = $employee?->relationLoaded('region') ? $employee->region : null;
        $array['province'] = $employee?->relationLoaded('province') ? $employee->province : null;

        $manager = $employee?->relationLoaded('reportingManager') ? $employee->reportingManager : null;
        $parentUser = $manager?->relationLoaded('user') ? $manager->user : null;
        $array['parent'] = $parentUser ? self::summary($parentUser) : null;

        $array['children'] = $employee?->relationLoaded('subordinates')
            ? $employee->subordinates
                ->map(fn (Employee $sub) => $sub->relationLoaded('user') && $sub->user ? self::summary($sub->user) : null)
                ->filter()->values()->all()
            : [];

        return $array;
    }

    /**
     * Whether the account may sign in: active, allowed to log in, and (for staff) the employee
     * record is active too.
     */
    public function canLogin(): bool
    {
        if (! $this->is_active || ! $this->can_login) {
            return false;
        }

        return $this->employee_id === null || (bool) $this->employee?->is_active;
    }

    /**
     * The mobile the staff-login SMS code goes to: the user's own, else the employee record's.
     */
    public function loginOtpPhone(): ?string
    {
        return $this->phone ?: $this->employee?->phone_primary;
    }

    /**
     * Whether the account is temporarily locked after too many wrong passwords.
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Clear Spatie's permission cache after a role or permission change.
     */
    public function forgetCachedPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The small user shape used for the manager / direct reports of a user.
     *
     * @return array{id: int, name: string, username: string|null, email: string, user_type: string, is_active: bool, can_login: bool}
     */
    private static function summary(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'user_type' => $user->user_type,
            'is_active' => $user->is_active,
            'can_login' => $user->can_login,
        ];
    }
}

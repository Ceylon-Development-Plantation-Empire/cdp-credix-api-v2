<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\UserType;
use App\Mail\UserCreateMail;
use App\Models\Employee;
use App\Models\User;
use App\Traits\ActivityLogTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Creating, changing and deactivating back-office logins (admin, staff).
 *
 * SECURITY:
 * - a password is never derived from personal data (the starter used the NIC). Either the
 *   administrator sets one (Password::defaults()) or a random one is generated and returned once;
 *   both are temporary: must_change_password is set;
 * - a password set or reset by an administrator, a deactivation or a role change raises the
 *   token_version, so the user's existing sessions end at once;
 * - users are never deleted (their name is on approvals, postings and the audit trail); "delete"
 *   deactivates the account permanently.
 */
class UserService
{
    use ActivityLogTrait;

    /**
     * Relations loaded for the user screens (the starter's response shape, see User::toArray()).
     */
    public const SCREEN_RELATIONS = [
        'employee.branch', 'employee.zonal', 'employee.region', 'employee.province',
        'employee.reportingManager.user', 'employee.designation', 'roles', 'permissions',
    ];

    /**
     * Create the login (and, for staff, the employee record) and send the welcome email.
     *
     * @param  array<string, mixed>  $data  Validated CreateUserRequest data.
     * @return array{user: User, temporary_password: string|null} The generated password is returned once, never stored in clear.
     */
    public function create(array $data, User $by): array
    {
        $generated = empty($data['password']) ? self::temporaryPassword() : null;

        $user = DB::transaction(function () use ($data, $generated) {
            $employeeId = null;

            if ($data['user_type'] === UserType::Staff->value) {
                $employeeId = Employee::create($this->employeeAttributes($data, null))->id;
            }

            $user = User::create([
                'name' => $data['name'],
                'username' => $data['username'] ?? null,
                'email' => mb_strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'] ?? $generated,
                'user_type' => $data['user_type'],
                'employee_id' => $employeeId,
                'is_active' => $data['is_active'] ?? true,
                'can_login' => $data['can_login'] ?? true,
                // Set by someone else: the user chooses their own at first sign-in.
                'must_change_password' => true,
            ]);

            $user->assignRole($data['role']);

            return $user;
        });

        $this->sendWelcomeMail($user, $data['role'], $by);
        // SECURITY: the payload is the validated data without any password (ActivityLogTrait also redacts).
        $this->logActivity('CREATE', 'User', "Created user: {$user->email}", collect($data)->except(['password', 'id_number'])->all());

        return ['user' => $user, 'temporary_password' => $generated];
    }

    /**
     * Change a user. A new password is an administrator reset.
     *
     * @param  array<string, mixed>  $data  Validated UpdateUserRequest data.
     */
    public function update(User $user, array $data): User
    {
        DB::transaction(function () use ($user, $data) {
            $targetType = $data['user_type'] ?? $user->user_type;
            $endSessions = false;

            if ($targetType === UserType::Staff->value) {
                $attributes = $this->employeeAttributes($data, $user->employee);

                if ($user->employee) {
                    $user->employee->update($attributes);
                } else {
                    // An admin turned into staff gets an employee record.
                    $user->employee_id = Employee::create($attributes + $this->employeeDefaults($data, $user))->id;
                }
            } elseif ($user->employee_id !== null) {
                // DECISION: staff turned admin keeps the employee record (history), only unlinked.
                $user->employee_id = null;
            }

            $user->fill(collect($data)->only(['name', 'username', 'phone', 'user_type', 'is_active', 'can_login'])->all());

            if (isset($data['email'])) {
                $user->email = mb_strtolower($data['email']);
            }

            if (isset($data['password'])) {
                $user->password = $data['password'];
                $user->must_change_password = true;
                $endSessions = true;
            }

            if ($user->isDirty(['is_active', 'can_login', 'user_type'])) {
                $endSessions = true;
            }

            if (isset($data['role'])) {
                $user->syncRoles([$data['role']]);
                $endSessions = true;
            }

            if ($endSessions) {
                $user->token_version++;
            }

            $user->save();
        });

        $this->logActivity('UPDATE', 'User', "Updated user: {$user->email}", collect($data)->except(['password', 'id_number'])->all());

        return $user->refresh();
    }

    /**
     * Switch a user active / inactive. Deactivating ends their sessions.
     */
    public function toggleStatus(User $user): User
    {
        $user->is_active = ! $user->is_active;
        $user->token_version++;
        $user->save();

        $this->logActivity('TOGGLE_STATUS', 'User', "Toggled user status: {$user->email} (".($user->is_active ? 'Active' : 'Inactive').')');

        return $user;
    }

    /**
     * "Delete" a user: deactivate permanently (cannot log in, sessions end). The row stays because
     * approvals, postings and the audit trail point at it.
     */
    public function deactivate(User $user): void
    {
        $user->forceFill([
            'is_active' => false,
            'can_login' => false,
            'token_version' => $user->token_version + 1,
        ])->save();

        $this->logActivity('DELETE', 'User', "Deactivated user (users are never deleted): {$user->email}");
    }

    /**
     * Employee columns taken from the request (only those that were sent, on an update).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function employeeAttributes(array $data, ?Employee $existing): array
    {
        $attributes = collect($data)->only([
            'employee_code', 'id_number', 'branch_id', 'zonal_id', 'region_id', 'province_id',
            'designation_id', 'reporting_manager_id',
        ])->all();

        if (isset($data['name'])) {
            $parts = explode(' ', trim($data['name']));
            $attributes['f_name'] = $data['f_name'] ?? $parts[0];
            $attributes['l_name'] = $data['l_name'] ?? (count($parts) > 1 ? end($parts) : $parts[0]);
            $attributes['full_name'] = $data['full_name'] ?? $data['name'];
            $attributes['name_with_initials'] = $data['name_with_initials'] ?? $data['name'];
        }

        if (array_key_exists('phone', $data)) {
            $attributes['phone'] = $data['phone'];
            $attributes['phone_primary'] = $data['phone'] ?? $existing?->phone_primary;
        }

        if (isset($data['email'])) {
            $attributes['email'] = mb_strtolower($data['email']);
        }

        return $existing === null ? $attributes + $this->employeeDefaults($data, null) : $attributes;
    }

    /**
     * Starter-kit defaults for HR fields the user screens do not collect.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function employeeDefaults(array $data, ?User $user): array
    {
        return [
            'employee_type' => $data['employee_type'] ?? 'permanent',
            'id_type' => $data['id_type'] ?? 'nic',
            'date_of_birth' => $data['date_of_birth'] ?? '2000-01-01',
            'email' => mb_strtolower($data['email'] ?? (string) $user?->email),
            'phone_primary' => $data['phone'] ?? '0000000000',
        ];
    }

    /**
     * The welcome email (sign-in email and login link; never the password).
     */
    protected function sendWelcomeMail(User $user, string $role, User $by): void
    {
        try {
            $user->load(self::SCREEN_RELATIONS);

            Mail::to($user->email)->send(new UserCreateMail(array_filter([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    // The template's "username" line shows how to sign in: the email.
                    'username' => $user->email,
                    'email' => $user->email,
                    'user_type' => $user->user_type,
                ],
                'role' => $role,
                'created_by' => $by->name,
                'login_url' => trim((string) (config('app.frontend_url') ?? config('app.url'))),
                'parent_name' => $user->parent?->name,
                'designation_name' => $user->employee?->designation?->name,
                'branch_name' => $user->branch?->name,
                'zone_name' => $user->zone?->name,
                'region_name' => $user->region?->name,
                'province_name' => $user->province?->name,
            ], fn ($value) => $value !== null)));

            $this->logActivity('EMAIL_SENT', 'User', "Welcome email sent to {$user->email}", ['user_id' => $user->id]);
        } catch (\Throwable $th) {
            report($th);
            $this->logActivity('EMAIL_FAILED', 'User', 'Failed to send the welcome email', ['user_id' => $user->id], 'error');
        }
    }

    /**
     * A random temporary password that satisfies Password::defaults() (upper, lower, digit, symbol, 16 long).
     */
    public static function temporaryPassword(): string
    {
        // Str::password uses random_int (CSPRNG) and includes each enabled set; one upper-case and one
        // lower-case letter, a digit and a symbol are appended so the mixed-case rule always holds.
        return Str::password(16, letters: true, numbers: true, symbols: true, spaces: false)
            .chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9).'#';
    }
}

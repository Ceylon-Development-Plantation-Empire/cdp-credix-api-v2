<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\LoanStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Traits\ActivityLogTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Deleting a user for good, after the administrator has seen everything the user is linked to.
 *
 * - a user who is the officer of an open loan or application cannot be deleted: the loans are reassigned first;
 * - a user who appears in nothing is deleted straight away;
 * - a user who appears in other records needs the sign-in name (or email) typed in full, then every record keeps its row and loses
 *   only the link to the user (the database is searched for every column that points at users, so a new table is covered too);
 * - the audit log keeps the person's name as text on their own entries, and one entry records the deletion with the full list.
 */
class UserDeletionService
{
    use ActivityLogTrait;

    /** @var array<string, list<string>>|null table => columns that point at users.id */
    protected static ?array $links = null;

    /**
     * What the user is linked to and whether the deletion is allowed.
     *
     * @return array<string, mixed>
     */
    public function impact(User $user): array
    {
        $records = $this->records($user);
        $loans = $this->openLoansOf($user);
        $total = array_sum(array_column($records, 'count'));

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'username' => $user->username],
            'can_delete' => $loans === [],
            'blocked_by' => $loans === [] ? [] : [[
                'code' => 'USER_HAS_ACTIVE_LOANS',
                'message' => count($loans).' open loan(s) or application(s) have this user as their officer. Reassign them first.',
            ]],
            'active_loans' => $loans,
            'records' => $records,
            'total' => $total,
            'requires_confirmation' => $total > 0,
            'confirm_text' => $this->confirmText($user),
            'warnings' => $total > 0
                ? ['These records are kept, but they will no longer name this user. The audit log keeps the name on the user\'s own entries.']
                : [],
        ];
    }

    /**
     * Delete the user for good.
     *
     * @return array<string, mixed> the impact as it was when the user was deleted
     */
    public function delete(User $user, User $by, ?string $confirm): array
    {
        return DB::transaction(function () use ($user, $by, $confirm) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $impact = $this->impact($user);

            if ($impact['active_loans']) {
                throw new BusinessRuleException('USER_HAS_ACTIVE_LOANS', $impact['blocked_by'][0]['message'], 409, ['active_loans' => $impact['active_loans']]);
            }

            if ($impact['requires_confirmation'] && $confirm !== $impact['confirm_text']) {
                throw new BusinessRuleException('USER_DELETE_CONFIRMATION_REQUIRED', 'Type the sign-in name exactly as shown to delete this user.', 422, ['confirm_text' => $impact['confirm_text']]);
            }

            $name = trim($user->name.' <'.$user->email.'>');
            $roles = $user->getRoleNames()->all();

            // The person's own audit entries keep their name as text; then every link to the user is cut.
            DB::table('activity_logs')->where('user_id', $user->id)->update(['user_name' => $name]);

            foreach (static::links() as $table => $columns) {
                foreach ($columns as $column) {
                    DB::table($table)->where($column, $user->id)->update([$column => null]);
                }
            }

            DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)->delete();

            if ($user->employee_id) {
                DB::table('employees')->where('id', $user->employee_id)->update(['is_active' => false]);
            }

            $user->syncRoles([]);
            $user->syncPermissions([]);
            $user->delete();

            $this->logActivity('DELETE', 'User', "Permanently deleted user {$name}", [
                'user' => ['id' => $impact['user']['id'], 'name' => $user->name, 'email' => $user->email, 'username' => $user->username, 'user_type' => $user->user_type, 'employee_id' => $user->employee_id, 'roles' => $roles],
                'records' => $impact['records'],
                'total' => $impact['total'],
                'deleted_by' => $by->id,
            ], 'warning');

            return $impact;
        }, attempts: 3);
    }

    /**
     * What the administrator must type: the sign-in name, or the email when the user has none.
     */
    public function confirmText(User $user): string
    {
        return (string) ($user->username ?: $user->email);
    }

    /**
     * @return list<array{public_id: string, application_no: string|null, status: string}>
     */
    protected function openLoansOf(User $user): array
    {
        $closed = array_map(fn (LoanStatus $status) => $status->value, LoanStatus::closed());

        return DB::table('loans')
            ->where('assigned_officer_id', $user->id)
            ->whereNull('deleted_at')
            ->whereNotIn('status', $closed)
            ->orderBy('id')
            ->get(['public_id', 'application_no', 'status'])
            ->map(fn ($loan) => ['public_id' => $loan->public_id, 'application_no' => $loan->application_no, 'status' => (string) $loan->status])
            ->all();
    }

    /**
     * Rows per table that point at the user (a row naming the user twice counts once).
     *
     * @return list<array{table: string, label: string, count: int}>
     */
    protected function records(User $user): array
    {
        $rows = [];

        foreach (static::links() as $table => $columns) {
            $count = DB::table($table)->where(function ($query) use ($columns, $user) {
                foreach ($columns as $column) {
                    $query->orWhere($column, $user->id);
                }
            })->count();

            if ($count > 0) {
                $rows[] = ['table' => $table, 'label' => $this->label($table), 'count' => $count];
            }
        }

        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $rows;
    }

    /**
     * Every column of every table that has a foreign key to users.
     *
     * @return array<string, list<string>>
     */
    protected static function links(): array
    {
        if (static::$links !== null) {
            return static::$links;
        }

        $links = [];

        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $key) {
                if ($key['foreign_table'] === 'users') {
                    foreach ($key['columns'] as $column) {
                        $links[$table['name']][] = $column;
                    }
                }
            }
        }

        return static::$links = $links;
    }

    protected function label(string $table): string
    {
        return [
            'activity_logs' => 'Audit log entries',
            'loan_activities' => 'Loan timeline entries',
            'loans' => 'Loans and applications',
            'customers' => 'Customers',
            'otp_challenges' => 'OTP codes sent',
            'borrower_groups' => 'Borrower groups',
            'collaterals' => 'Collateral',
            'documents' => 'Documents',
            'approval_requests' => 'Approval requests',
            'loan_transactions' => 'Loan payments and postings',
            'gl_journals' => 'Accounting journals',
            'recovery_cases' => 'Recovery cases',
            'legal_documents' => 'Legal documents',
            'customer_financials' => 'Customer financial lines',
        ][$table] ?? Str::headline($table);
    }
}

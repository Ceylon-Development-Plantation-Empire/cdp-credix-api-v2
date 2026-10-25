<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every module now has its own Index / Create / Update / Delete permission (owner decision 25 Oct 2026).
 *
 * The combined "... Manage" permissions are replaced by three, and every role (and user) that held the
 * old one receives all three, so nobody gains or loses anything the day this runs. Roles that could read
 * a parent record keep reading its parts through the new Index permissions. The new permissions with no
 * old counterpart (Loan Delete, Employee …, Setting Create …) are given to nobody: a Super Admin holds them
 * through PermissionsSeeder and hands them out to roles from the role screen.
 */
return new class extends Migration
{
    /** old permission => [group, new permissions] */
    private const SPLIT = [
        'Lookup Manage' => ['System Settings Permissions', ['Lookup Create', 'Lookup Update', 'Lookup Delete']],
        'Charge Type Manage' => ['Product Catalogue Permissions', ['Charge Type Create', 'Charge Type Update', 'Charge Type Delete']],
        'Customer Financial Manage' => ['Customer Management Permissions', ['Customer Financial Create', 'Customer Financial Update', 'Customer Financial Delete']],
        'Customer Bank Account Manage' => ['Customer Management Permissions', ['Customer Bank Account Create', 'Customer Bank Account Update', 'Customer Bank Account Delete']],
        'Borrower Group Member Manage' => ['Borrower Group Permissions', ['Borrower Group Member Create', 'Borrower Group Member Update', 'Borrower Group Member Delete']],
        'Loan Guarantor Manage' => ['Loan Permissions', ['Loan Guarantor Create', 'Loan Guarantor Update', 'Loan Guarantor Delete']],
        'Loan Collateral Manage' => ['Loan Permissions', ['Loan Collateral Create', 'Loan Collateral Update', 'Loan Collateral Delete']],
        'Legal Document Manage' => ['Legal Permissions', ['Legal Document Create', 'Legal Document Update', 'Legal Document Delete']],
        'Legal Template Manage' => ['Legal Permissions', ['Legal Template Create', 'Legal Template Update', 'Legal Template Delete']],
        'Template Manage' => ['Notification Permissions', ['Template Create', 'Template Update', 'Template Delete']],
        'GL Account Manage' => ['Accounting Permissions', ['GL Account Create', 'GL Account Update', 'GL Account Delete']],
    ];

    /** held permission => [group, permissions that were implied by it before] */
    private const ALSO = [
        'Customer Index' => ['Customer Management Permissions', ['Customer Financial Index', 'Customer Bank Account Index']],
        'Loan Index' => ['Loan Permissions', ['Loan Guarantor Index', 'Loan Collateral Index', 'Loan Note Index']],
        'Loan Update' => ['Loan Permissions', ['Loan Note Create', 'Loan Note Update', 'Loan Note Delete']],
        'Legal Document Index' => ['Legal Permissions', ['Legal Template Index']],
        'Notification Mark Read' => ['System Notifications Management Permissions', ['Notification Delete']],
    ];

    public function up(): void
    {
        foreach (self::SPLIT as $old => [$group, $new]) {
            $this->give($old, $group, $new);
            $this->remove($old);
        }

        foreach (self::ALSO as $held => [$group, $new]) {
            $this->give($held, $group, $new);
        }

        $this->forgetCache();
    }

    public function down(): void
    {
        // The split is one-way: the three permissions stay. Re-grant "Manage" by hand if ever needed.
    }

    /**
     * Everyone holding $held also receives each of $new (created when missing).
     *
     * @param  list<string>  $new
     */
    private function give(string $held, string $group, array $new): void
    {
        $heldId = DB::table('permissions')->where('name', $held)->where('guard_name', 'api')->value('id');

        $ids = [];
        foreach ($new as $name) {
            $ids[] = DB::table('permissions')->where('name', $name)->where('guard_name', 'api')->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'api', 'group_name' => $group, 'created_at' => now(), 'updated_at' => now()]);
        }

        if ($heldId === null) {
            return;
        }

        foreach (DB::table('role_has_permissions')->where('permission_id', $heldId)->pluck('role_id') as $roleId) {
            foreach ($ids as $id) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }

        foreach (DB::table('model_has_permissions')->where('permission_id', $heldId)->get() as $row) {
            foreach ($ids as $id) {
                DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $id, 'model_type' => $row->model_type, 'model_id' => $row->model_id]);
            }
        }
    }

    private function remove(string $name): void
    {
        $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'api')->value('id');

        if ($id === null) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $id)->delete();
        DB::table('model_has_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }

    private function forgetCache(): void
    {
        try {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Throwable) {
            // The cache is rebuilt on the next request anyway.
        }
    }
};

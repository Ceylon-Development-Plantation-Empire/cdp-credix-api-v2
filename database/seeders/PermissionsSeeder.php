<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            /* Access Management */
            ['name' => 'Permission Index', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Permission Create', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Permission Update', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Permission Delete', 'group_name' => 'Access Management Permissions'],

            ['name' => 'Role Index', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Role Create', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Role Update', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Role Delete', 'group_name' => 'Access Management Permissions'],

            /* User Management */
            ['name' => 'User Index', 'group_name' => 'User Management Permissions'],
            ['name' => 'User Create', 'group_name' => 'User Management Permissions'],
            ['name' => 'User Update', 'group_name' => 'User Management Permissions'],
            ['name' => 'User Delete', 'group_name' => 'User Management Permissions'],
            ['name' => 'User Toggle Status', 'group_name' => 'User Management Permissions'],

            /* Country Management */
            ['name' => 'Country Index', 'group_name' => 'Country Management Permissions'],
            ['name' => 'Country Create', 'group_name' => 'Country Management Permissions'],
            ['name' => 'Country Update', 'group_name' => 'Country Management Permissions'],
            ['name' => 'Country Delete', 'group_name' => 'Country Management Permissions'],
            ['name' => 'Country Toggle Status', 'group_name' => 'Country Management Permissions'],

            /* Province Management */
            ['name' => 'Province Index', 'group_name' => 'Province Management Permissions'],
            ['name' => 'Province Create', 'group_name' => 'Province Management Permissions'],
            ['name' => 'Province Update', 'group_name' => 'Province Management Permissions'],
            ['name' => 'Province Delete', 'group_name' => 'Province Management Permissions'],
            ['name' => 'Province Toggle Status', 'group_name' => 'Province Management Permissions'],

            /* Zonal Management */
            ['name' => 'Zonal Index', 'group_name' => 'Zonal Management Permissions'],
            ['name' => 'Zonal Create', 'group_name' => 'Zonal Management Permissions'],
            ['name' => 'Zonal Update', 'group_name' => 'Zonal Management Permissions'],
            ['name' => 'Zonal Delete', 'group_name' => 'Zonal Management Permissions'],
            ['name' => 'Zonal Toggle Status', 'group_name' => 'Zonal Management Permissions'],

            /* Region Management */
            ['name' => 'Region Index', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Region Create', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Region Update', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Region Delete', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Region Toggle Status', 'group_name' => 'Region Management Permissions'],

            /* Branch Management */
            ['name' => 'Branch Index', 'group_name' => 'Branch Management Permissions'],
            ['name' => 'Branch Create', 'group_name' => 'Branch Management Permissions'],
            ['name' => 'Branch Update', 'group_name' => 'Branch Management Permissions'],
            ['name' => 'Branch Delete', 'group_name' => 'Branch Management Permissions'],
            ['name' => 'Branch Toggle Status', 'group_name' => 'Branch Management Permissions'],

            /* Department Management */
            ['name' => 'Department Index', 'group_name' => 'Department Management Permissions'],
            ['name' => 'Department Create', 'group_name' => 'Department Management Permissions'],
            ['name' => 'Department Update', 'group_name' => 'Department Management Permissions'],
            ['name' => 'Department Delete', 'group_name' => 'Department Management Permissions'],
            ['name' => 'Department Toggle Status', 'group_name' => 'Department Management Permissions'],

            /* Designation Management */
            ['name' => 'Designation Index', 'group_name' => 'Designation Management Permissions'],
            ['name' => 'Designation Create', 'group_name' => 'Designation Management Permissions'],
            ['name' => 'Designation Update', 'group_name' => 'Designation Management Permissions'],
            ['name' => 'Designation Delete', 'group_name' => 'Designation Management Permissions'],
            ['name' => 'Designation Toggle Status', 'group_name' => 'Designation Management Permissions'],

            /* Group Management */
            ['name' => 'Group Index', 'group_name' => 'Group Management Permissions'],
            ['name' => 'Group Create', 'group_name' => 'Group Management Permissions'],
            ['name' => 'Group Update', 'group_name' => 'Group Management Permissions'],
            ['name' => 'Group Delete', 'group_name' => 'Group Management Permissions'],
            ['name' => 'Group Toggle Status', 'group_name' => 'Group Management Permissions'],

            /* Activity Log Management */
            ['name' => 'Activity Log Index', 'group_name' => 'Activity Log Management Permissions'],

            /* Employee Hierarchy Management */
            ['name' => 'Employee Hierarchy View', 'group_name' => 'Employee Hierarchy Management Permissions'],
            ['name' => 'Employee Hierarchy Reassign', 'group_name' => 'Employee Hierarchy Management Permissions'],

            /* System Notifications Management */
            ['name' => 'Notification Index', 'group_name' => 'System Notifications Management Permissions'],
            ['name' => 'Notification Mark Read', 'group_name' => 'System Notifications Management Permissions'],

            /* Database Management */
            ['name' => 'Database Export', 'group_name' => 'Database Management Permissions'],

            /* Import Management */
            ['name' => 'Import Index', 'group_name' => 'Import Management Permissions'],
            ['name' => 'Bulk Import', 'group_name' => 'Import Management Permissions'],

            /* System Settings */
            ['name' => 'Setting Index', 'group_name' => 'System Settings Permissions'],
            ['name' => 'Setting Update', 'group_name' => 'System Settings Permissions'],
            // Code lists (banks, categories, relationships, branch / employee types, custom fields).
            ['name' => 'Lookup Index', 'group_name' => 'System Settings Permissions'],
            ['name' => 'Lookup Manage', 'group_name' => 'System Settings Permissions'],
            // Fee and charge types used by products.
            ['name' => 'Charge Type Index', 'group_name' => 'Product Catalogue Permissions'],
            ['name' => 'Charge Type Manage', 'group_name' => 'Product Catalogue Permissions'],
            ['name' => 'Lending Margin Index', 'group_name' => 'Product Catalogue Permissions'],
            ['name' => 'Lending Margin Manage', 'group_name' => 'Product Catalogue Permissions'],

            /* Product Catalogue */
            ['name' => 'Product Category Index', 'group_name' => 'Product Catalogue Permissions'],
            ['name' => 'Product Category Create', 'group_name' => 'Product Catalogue Permissions'],
            ['name' => 'Product Category Update', 'group_name' => 'Product Catalogue Permissions'],
            ['name' => 'Product Category Toggle Status', 'group_name' => 'Product Catalogue Permissions'],
            ['name' => 'Product Index', 'group_name' => 'Product Catalogue Permissions'],
            ['name' => 'Product Create', 'group_name' => 'Product Catalogue Permissions'],
            ['name' => 'Product Update', 'group_name' => 'Product Catalogue Permissions'],
            ['name' => 'Product Delete', 'group_name' => 'Product Catalogue Permissions'],
            ['name' => 'Product Toggle Status', 'group_name' => 'Product Catalogue Permissions'],
            ['name' => 'Product Shariah Submit', 'group_name' => 'Product Catalogue Permissions'],
            // Give this only to Shariah board members / their delegate.
            ['name' => 'Product Shariah Approve', 'group_name' => 'Product Catalogue Permissions'],

            /* Approvals (maker-checker inbox) */
            ['name' => 'Approval Request Index', 'group_name' => 'Approval Permissions'],

            /* Documents */
            ['name' => 'Document Type Index', 'group_name' => 'Document Configuration Permissions'],
            ['name' => 'Document Type Create', 'group_name' => 'Document Configuration Permissions'],
            ['name' => 'Document Type Update', 'group_name' => 'Document Configuration Permissions'],
            ['name' => 'Document Type Toggle Status', 'group_name' => 'Document Configuration Permissions'],
            ['name' => 'Document Requirement Index', 'group_name' => 'Document Configuration Permissions'],
            ['name' => 'Document Requirement Create', 'group_name' => 'Document Configuration Permissions'],
            ['name' => 'Document Requirement Update', 'group_name' => 'Document Configuration Permissions'],
            ['name' => 'Document Requirement Delete', 'group_name' => 'Document Configuration Permissions'],

            /* Branch data access */
            // Head office: see and work on records of every branch. Without it a user sees only their own branch.
            ['name' => 'Branch Data All', 'group_name' => 'Branch Access Permissions'],

            /* Customers */
            ['name' => 'Customer Index', 'group_name' => 'Customer Management Permissions'],
            ['name' => 'Customer Create', 'group_name' => 'Customer Management Permissions'],
            ['name' => 'Customer Update', 'group_name' => 'Customer Management Permissions'],
            ['name' => 'Customer Toggle Status', 'group_name' => 'Customer Management Permissions'],
            // Full NIC, date of birth, phone, email and bank account numbers instead of masked values.
            ['name' => 'Customer View Sensitive', 'group_name' => 'Customer Management Permissions'],
            ['name' => 'Customer KYC Verify', 'group_name' => 'Customer Management Permissions'],
            ['name' => 'Customer Financial Manage', 'group_name' => 'Customer Management Permissions'],
            ['name' => 'Customer Financial Verify', 'group_name' => 'Customer Management Permissions'],
            ['name' => 'Customer Bank Account Manage', 'group_name' => 'Customer Management Permissions'],

            /* Documents */
            ['name' => 'Document Index', 'group_name' => 'Document Permissions'],
            ['name' => 'Document Upload', 'group_name' => 'Document Permissions'],
            ['name' => 'Document Verify', 'group_name' => 'Document Permissions'],
            ['name' => 'Document Delete', 'group_name' => 'Document Permissions'],

            /* Borrower Groups */
            ['name' => 'Borrower Group Index', 'group_name' => 'Borrower Group Permissions'],
            ['name' => 'Borrower Group Create', 'group_name' => 'Borrower Group Permissions'],
            ['name' => 'Borrower Group Update', 'group_name' => 'Borrower Group Permissions'],
            ['name' => 'Borrower Group Member Manage', 'group_name' => 'Borrower Group Permissions'],

            /* Loans */
            ['name' => 'Loan Index', 'group_name' => 'Loan Permissions'],
            ['name' => 'Loan Create', 'group_name' => 'Loan Permissions'],
            ['name' => 'Loan Update', 'group_name' => 'Loan Permissions'],
            ['name' => 'Loan Submit', 'group_name' => 'Loan Permissions'],
            ['name' => 'Loan Guarantor Manage', 'group_name' => 'Loan Permissions'],
            ['name' => 'Loan Collateral Manage', 'group_name' => 'Loan Permissions'],
            ['name' => 'Loan Cancel', 'group_name' => 'Loan Permissions'],

            /* Loan decisions (maker-checker: one person cannot do two of these steps on the same loan) */
            ['name' => 'Loan Review', 'group_name' => 'Loan Decision Permissions'],
            ['name' => 'Loan Verify', 'group_name' => 'Loan Decision Permissions'],
            ['name' => 'Loan Collateral Verify', 'group_name' => 'Loan Decision Permissions'],
            // The amount a user can approve also needs roles.max_approval_amount on one of their roles.
            ['name' => 'Loan Approve', 'group_name' => 'Loan Decision Permissions'],
            ['name' => 'Loan Reject', 'group_name' => 'Loan Decision Permissions'],

            /* Loan operations */
            ['name' => 'Loan Offer Manage', 'group_name' => 'Loan Operations Permissions'],
            ['name' => 'Loan Collateral Perfect', 'group_name' => 'Loan Operations Permissions'],
            ['name' => 'Loan Disburse', 'group_name' => 'Loan Operations Permissions'],

            /* Servicing (requests) */
            ['name' => 'Payment Index', 'group_name' => 'Servicing Permissions'],
            ['name' => 'Payment Create', 'group_name' => 'Servicing Permissions'],
            ['name' => 'Payment Reversal Request', 'group_name' => 'Servicing Permissions'],
            ['name' => 'Penalty Waiver Request', 'group_name' => 'Servicing Permissions'],
            ['name' => 'Loan Restructure Request', 'group_name' => 'Servicing Permissions'],
            ['name' => 'Loan Settle', 'group_name' => 'Servicing Permissions'],
            ['name' => 'Loan Write Off Request', 'group_name' => 'Servicing Permissions'],
            ['name' => 'Gold Loan Renew', 'group_name' => 'Servicing Permissions'],
            // Money collected on a written-off loan (recovery agents' collections are posted here).
            ['name' => 'Write Off Recovery Create', 'group_name' => 'Servicing Permissions'],

            /* Servicing (approvals: decided in the approval inbox by someone other than the requester) */
            ['name' => 'Payment Reversal Approve', 'group_name' => 'Servicing Approval Permissions'],
            ['name' => 'Payment Backdate Approve', 'group_name' => 'Servicing Approval Permissions'],
            ['name' => 'Penalty Waiver Approve', 'group_name' => 'Servicing Approval Permissions'],
            ['name' => 'Loan Restructure Approve', 'group_name' => 'Servicing Approval Permissions'],
            ['name' => 'Loan Write Off Approve', 'group_name' => 'Servicing Approval Permissions'],

            /* Recovery */
            ['name' => 'Recovery Case Index', 'group_name' => 'Recovery Permissions'],
            // Recovery manager: assigns agents / agencies and moves cases to legal; is alerted at 180 DPD.
            ['name' => 'Recovery Case Assign', 'group_name' => 'Recovery Permissions'],
            // Internal recovery agent: records calls, visits and promises to pay.
            ['name' => 'Recovery Case Work', 'group_name' => 'Recovery Permissions'],

            /* Notifications */
            ['name' => 'Template Index', 'group_name' => 'Notification Permissions'],
            ['name' => 'Template Manage', 'group_name' => 'Notification Permissions'],
            ['name' => 'Notification Log Index', 'group_name' => 'Notification Permissions'],

            /* Reports */
            ['name' => 'Report View', 'group_name' => 'Report Permissions'],
            ['name' => 'Dashboard View', 'group_name' => 'Report Permissions'],

            /* General ledger */
            ['name' => 'GL Account Index', 'group_name' => 'Accounting Permissions'],
            ['name' => 'GL Account Manage', 'group_name' => 'Accounting Permissions'],
            ['name' => 'GL Journal Index', 'group_name' => 'Accounting Permissions'],
            ['name' => 'GL Journal Request', 'group_name' => 'Accounting Permissions'],
            // Decided in the approval inbox by someone other than the person who entered the journal.
            ['name' => 'GL Journal Approve', 'group_name' => 'Accounting Permissions'],
            ['name' => 'GL Report View', 'group_name' => 'Accounting Permissions'],
            ['name' => 'GL Period Close', 'group_name' => 'Accounting Permissions'],
        ];

        foreach ($permissions as $permission) {
            // Found by name (what the code checks); the group is only a label and may be regrouped.
            Permission::updateOrCreate(
                ['name' => $permission['name'], 'guard_name' => 'api'],
                ['group_name' => $permission['group_name']],
            );
        }

        // The Super Admin role always holds every permission and cannot be renamed or deleted.
        $role = Role::firstOrCreate(['guard_name' => 'api', 'name' => 'Super Admin']);
        $role->forceFill(['is_protected' => true])->save();

        $allPermissions = Permission::all();
        $role->syncPermissions($allPermissions);
    }
}

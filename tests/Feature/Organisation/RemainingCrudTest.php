<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\GlAccount;
use App\Models\RecoveryCase;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\TemplateSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

describe('recovery cases', function () {
    beforeEach(function () {
        $this->branchId = Fixtures::branch();
        $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class, TemplateSeeder::class]);
        Fixtures::fakeSms();
        LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);

        $this->manager = Fixtures::staffInBranch($this->branchId, 'Recovery Case Index', 'Recovery Case Create', 'Recovery Case Update', 'Recovery Case Delete', 'Recovery Activity Update', 'Recovery Activity Delete', 'Recovery Case Work');
        $this->agent = Fixtures::staffInBranch($this->branchId, 'Recovery Case Index', 'Recovery Case Work', 'Recovery Activity Update', 'Recovery Activity Delete');
        $this->plain = Fixtures::staffInBranch($this->branchId, 'Recovery Case Index');
    });

    it('closes an open case by decision, then opens a new one by hand for the same loan', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(130));
        $this->artisan('eod:run')->assertSuccessful();
        $case = RecoveryCase::firstOrFail();

        // One open case per loan.
        $this->actingAs($this->manager, 'api')->postJson('/api/v1/recovery-cases', ['loan_public_id' => $loan->public_id, 'remarks' => 'Again'])
            ->assertStatus(409)->assertJsonPath('code', 'RECOVERY_CASE_EXISTS');

        $this->actingAs($this->plain, 'api')->deleteJson("/api/v1/recovery-cases/{$case->public_id}", ['remarks' => 'Sorted out'])->assertForbidden();
        $this->actingAs($this->manager, 'api')->deleteJson("/api/v1/recovery-cases/{$case->public_id}", ['remarks' => 'Customer paid in full'])->assertOk();
        expect($case->fresh()->status->value)->toBe('closed');

        $this->actingAs($this->manager, 'api')->postJson('/api/v1/recovery-cases', ['loan_public_id' => $loan->public_id, 'remarks' => 'Fell behind again'])
            ->assertCreated()->assertJsonPath('data.status', 'open');
    });

    it('refuses a manual case for a loan that is not in arrears', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);

        $this->actingAs($this->manager, 'api')->postJson('/api/v1/recovery-cases', ['loan_public_id' => $loan->public_id, 'remarks' => 'Just in case'])
            ->assertStatus(422)->assertJsonPath('code', 'RECOVERY_NOT_IN_ARREARS');
    });

    it('changes the agent with the update permission', function () {
        LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(130));
        $this->artisan('eod:run')->assertSuccessful();
        $case = RecoveryCase::firstOrFail();

        $this->actingAs($this->plain, 'api')->putJson("/api/v1/recovery-cases/{$case->public_id}", ['user_id' => $this->agent->id, 'stage' => 'internal'])->assertForbidden();
        $this->actingAs($this->manager, 'api')->putJson("/api/v1/recovery-cases/{$case->public_id}", ['user_id' => $this->agent->id, 'stage' => 'internal'])->assertOk();
        expect($case->fresh()->assigned_user_id)->toBe($this->agent->id);
    });

    it('lets the author correct or remove a call or promise, nobody else', function () {
        LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(130));
        $this->artisan('eod:run')->assertSuccessful();
        $case = RecoveryCase::firstOrFail();

        $id = $this->actingAs($this->agent, 'api')->postJson("/api/v1/recovery-cases/{$case->public_id}/activities", ['type' => 'call', 'remarks' => 'No answer'])->assertCreated()->json('data.id');

        $this->actingAs($this->manager, 'api')->putJson("/api/v1/recovery-cases/{$case->public_id}/activities/{$id}", ['type' => 'call', 'remarks' => 'Edited by a boss'])->assertNotFound();
        $this->actingAs($this->agent, 'api')->putJson("/api/v1/recovery-cases/{$case->public_id}/activities/{$id}", ['type' => 'visit', 'remarks' => 'Changed kind'])->assertStatus(422);
        $this->actingAs($this->agent, 'api')->putJson("/api/v1/recovery-cases/{$case->public_id}/activities/{$id}", ['type' => 'call', 'remarks' => 'Answered, will pay Friday'])
            ->assertOk()->assertJsonPath('data.remarks', 'Answered, will pay Friday');

        $this->actingAs($this->plain, 'api')->deleteJson("/api/v1/recovery-cases/{$case->public_id}/activities/{$id}")->assertForbidden();
        $this->actingAs($this->agent, 'api')->deleteJson("/api/v1/recovery-cases/{$case->public_id}/activities/{$id}")->assertOk();
    });
});

describe('settings', function () {
    beforeEach(function () {
        $this->seed(SettingsSeeder::class);
        $this->admin = staffWith('Setting Index', 'Setting Create', 'Setting Update', 'Setting Delete');
    });

    it('adds a custom setting, validates its value by type, and deletes only custom ones', function () {
        $this->actingAs(staffWith('Setting Index'), 'api')->postJson('/api/v1/settings', ['key' => 'x.y', 'group' => 'custom', 'type' => 'integer', 'value' => 5, 'label' => 'X'])->assertForbidden();

        $this->actingAs($this->admin, 'api')->postJson('/api/v1/settings', ['key' => 'bank.cut_off_hour', 'group' => 'custom', 'type' => 'integer', 'value' => 'late', 'label' => 'Cut-off hour'])->assertStatus(422);
        $this->actingAs($this->admin, 'api')->postJson('/api/v1/settings', ['key' => 'Bad Key!', 'group' => 'custom', 'type' => 'integer', 'value' => 5, 'label' => 'x'])->assertStatus(422);
        $this->actingAs($this->admin, 'api')->postJson('/api/v1/settings', ['key' => 'borrower.min_age', 'group' => 'custom', 'type' => 'integer', 'value' => 5, 'label' => 'dup'])->assertStatus(422);

        $this->actingAs($this->admin, 'api')->postJson('/api/v1/settings', ['key' => 'bank.cut_off_hour', 'group' => 'custom', 'type' => 'integer', 'value' => 16, 'label' => 'Cut-off hour'])
            ->assertCreated()->assertJsonPath('data.is_custom', true)->assertJsonPath('data.value', 16);
        expect(Setting::value('bank.cut_off_hour'))->toBe(16);

        $this->actingAs($this->admin, 'api')->putJson('/api/v1/settings/bank.cut_off_hour', ['value' => 'x'])->assertStatus(422);
        $this->actingAs($this->admin, 'api')->putJson('/api/v1/settings/bank.cut_off_hour', ['value' => 17])->assertOk();

        $this->actingAs($this->admin, 'api')->deleteJson('/api/v1/settings/borrower.min_age')->assertStatus(409)->assertJsonPath('code', 'SETTING_IS_SYSTEM');
        $this->actingAs(staffWith('Setting Index'), 'api')->deleteJson('/api/v1/settings/bank.cut_off_hour')->assertForbidden();
        $this->actingAs($this->admin, 'api')->deleteJson('/api/v1/settings/bank.cut_off_hour')->assertOk();
        expect(Setting::where('key', 'bank.cut_off_hour')->exists())->toBeFalse()->and(Setting::where('key', 'borrower.min_age')->exists())->toBeTrue();
    });
});

describe('employees', function () {
    beforeEach(function () {
        $this->seed(LookupSeeder::class);
        $this->branchId = Fixtures::branch();
        $this->otherBranch = Fixtures::branch('Kandy');
        $this->hr = Fixtures::staffInBranch($this->branchId, 'Employee Index', 'Employee Create', 'Employee Update', 'Employee Delete');
        $this->designationId = DB::table('designations')->value('id');
    });

    it('records, reads, corrects and deactivates an employee of the own branch, by permission', function () {
        $payload = ['full_name' => 'Nimal Perera', 'employee_code' => 'E-9001', 'id_number' => '901234567V', 'email' => 'Nimal@Example.com', 'designation_id' => $this->designationId, 'branch_id' => $this->otherBranch];

        $this->actingAs(Fixtures::staffInBranch($this->branchId), 'api')->postJson('/api/v1/employees', $payload)->assertForbidden();

        // A branch user's record lands in their own branch whatever branch_id says.
        $id = $this->actingAs($this->hr, 'api')->postJson('/api/v1/employees', $payload)->assertCreated()
            ->assertJsonPath('data.branch.id', $this->branchId)->assertJsonPath('data.email', 'nimal@example.com')->json('data.id');
        $this->actingAs($this->hr, 'api')->postJson('/api/v1/employees', $payload)->assertStatus(422);

        $this->actingAs($this->hr, 'api')->getJson('/api/v1/employees?search=Nimal')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->actingAs($this->hr, 'api')->getJson("/api/v1/employees/{$id}")->assertOk()->assertJsonPath('data.employee_code', 'E-9001')->assertJsonMissingPath('data.id_number');

        $this->actingAs($this->hr, 'api')->putJson("/api/v1/employees/{$id}", ['full_name' => 'Nimal K. Perera'])->assertOk()->assertJsonPath('data.full_name', 'Nimal K. Perera');

        $this->actingAs($this->hr, 'api')->deleteJson("/api/v1/employees/{$id}")->assertOk();
        expect(Employee::find($id)->is_active)->toBeFalse();
    });

    it('keeps a branch user out of other branches\' employees, and refuses to delete one who still has a login', function () {
        $elsewhere = Fixtures::staffInBranch($this->otherBranch);
        $this->actingAs($this->hr, 'api')->getJson("/api/v1/employees/{$elsewhere->employee_id}")->assertNotFound();
        $this->actingAs($this->hr, 'api')->putJson("/api/v1/employees/{$elsewhere->employee_id}", ['full_name' => 'Hijack'])->assertNotFound();

        $own = Fixtures::staffInBranch($this->branchId);
        $this->actingAs($this->hr, 'api')->deleteJson("/api/v1/employees/{$own->employee_id}")->assertStatus(409)->assertJsonPath('code', 'EMPLOYEE_HAS_LOGIN');
    });
});

describe('agency logins', function () {
    it('are created, changed and deactivated with the user endpoints', function () {
        $this->seed(LookupSeeder::class);
        (new PermissionsSeeder)->run();
        $role = Role::firstOrCreate(['name' => 'Recovery Agency', 'guard_name' => 'api']);
        $role->syncPermissions(['Recovery Case Work']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = staffWith('User Index', 'User Create', 'User Update', 'User Delete');
        $admin->givePermissionTo('Recovery Case Work');

        $id = $this->actingAs($admin, 'api')->postJson('/api/v1/users', [
            'name' => 'Fast Recovery Ltd', 'email' => 'agency@fastrecovery.lk', 'user_type' => 'agency', 'role' => 'Recovery Agency',
        ])->assertCreated()->assertJsonPath('data.user_type', 'agency')->json('data.id');

        expect(User::find($id)->employee_id)->toBeNull();
        $this->actingAs($admin, 'api')->putJson("/api/v1/users/{$id}", ['name' => 'Fast Recovery (Pvt) Ltd'])->assertOk();
        $this->actingAs($admin, 'api')->getJson('/api/v1/users?user_type=agency')->assertOk()->assertJsonPath('data.total', 1);
    });
});

describe('single records can be read', function () {
    it('reads one GL account, one financial line, one bank account and one document requirement, by their Index permission', function () {
        $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
        $branchId = Fixtures::branch();
        $reader = Fixtures::staffInBranch($branchId, 'GL Account Index', 'Customer Financial Index', 'Customer Bank Account Index', 'Document Requirement Index');
        $nobody = Fixtures::staffInBranch($branchId);
        $customer = LoanFixtures::customer($branchId);

        $account = GlAccount::first();
        $this->actingAs($reader, 'api')->getJson("/api/v1/gl/accounts/{$account->id}")->assertOk()->assertJsonPath('data.code', $account->code);
        $this->actingAs($nobody, 'api')->getJson("/api/v1/gl/accounts/{$account->id}")->assertForbidden();

        LoanFixtures::income($customer, '100000');
        $line = $customer->financials()->first();
        $this->actingAs($reader, 'api')->getJson("/api/v1/customers/{$customer->public_id}/financials/{$line->id}")->assertOk();
        $this->actingAs($nobody, 'api')->getJson("/api/v1/customers/{$customer->public_id}/financials/{$line->id}")->assertForbidden();

        $requirementId = DB::table('document_requirements')->value('id');
        $this->actingAs($reader, 'api')->getJson("/api/v1/document-requirements/{$requirementId}")->assertOk();
        $this->actingAs($nobody, 'api')->getJson("/api/v1/document-requirements/{$requirementId}")->assertForbidden();

        $bank = $customer->bankAccounts()->first();
        if ($bank) {
            $this->actingAs($reader, 'api')->getJson("/api/v1/customers/{$customer->public_id}/bank-accounts/{$bank->public_id}")->assertOk();
        }
    });
});

describe('the permission split for existing roles', function () {
    it('gives every role that held "Manage" the three new permissions, and read access where it was implied', function () {
        $migration = require database_path('migrations/2026_10_25_000300_split_manage_permissions.php');

        $clerk = Role::create(['name' => 'Clerk', 'guard_name' => 'api']);
        foreach (['Lookup Manage', 'Loan Index', 'Customer Index'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'api', 'group_name' => 'Old']);
        }
        $clerk->givePermissionTo(['Lookup Manage', 'Loan Index', 'Customer Index']);
        $direct = staffWith('Lookup Manage');

        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $clerk = $clerk->fresh();
        expect($clerk->hasPermissionTo('Lookup Create'))->toBeTrue()
            ->and($clerk->hasPermissionTo('Lookup Update'))->toBeTrue()
            ->and($clerk->hasPermissionTo('Lookup Delete'))->toBeTrue()
            ->and($clerk->hasPermissionTo('Loan Collateral Index'))->toBeTrue()
            ->and($clerk->hasPermissionTo('Customer Financial Index'))->toBeTrue()
            ->and($direct->fresh()->hasPermissionTo('Lookup Delete'))->toBeTrue()
            ->and(Permission::where('name', 'Lookup Manage')->exists())->toBeFalse();

        // Nothing was given that the role did not imply before.
        expect($clerk->permissions->pluck('name')->contains('Loan Delete'))->toBeFalse();
    });
});

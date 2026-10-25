<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Fixtures;

/**
 * Nobody can hand out more access than they hold: a role, a user's role and a CSV import are all
 * limited by the permissions of the person doing it.
 */
function superAdmin(): User
{
    // As in production: the seeder creates every permission and gives them all to the Super Admin role.
    (new Database\Seeders\PermissionsSeeder)->run();
    $role = Role::where('name', 'Super Admin')->where('guard_name', 'api')->first();
    $user = User::factory()->create(['user_type' => 'admin']);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function roleWith(string $name, string ...$permissions): Role
{
    $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'api']);

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api'], ['group_name' => 'Tests']);
    }

    $role->syncPermissions($permissions);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $role;
}

describe('assigning a role to a user', function () {
    beforeEach(function () {
        $this->seed(LookupSeeder::class);
        $this->branchId = Fixtures::branch();
    });

    it('refuses the Super Admin role from anyone who is not a Super Admin, themselves included', function () {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'api']);
        $me = Fixtures::staffInBranch($this->branchId, 'User Update', 'User Create');

        $this->actingAs($me, 'api')->putJson("/api/v1/users/{$me->id}", ['name' => 'Me', 'role' => 'Super Admin'])->assertForbidden();
        expect($me->fresh()->hasRole('Super Admin'))->toBeFalse();
    });

    it('refuses a role that holds permissions the assigner lacks, and accepts one within their own', function () {
        roleWith('Auditor', 'Database Export');
        roleWith('Clerk', 'User Update');
        $manager = Fixtures::staffInBranch($this->branchId, 'User Update');
        $other = Fixtures::staffInBranch($this->branchId);

        $this->actingAs($manager, 'api')->putJson("/api/v1/users/{$other->id}", ['name' => 'Other', 'role' => 'Auditor'])->assertForbidden();
        $this->actingAs($manager, 'api')->putJson("/api/v1/users/{$other->id}", ['name' => 'Other', 'role' => 'Clerk'])->assertOk();
        expect($other->fresh()->hasRole('Clerk'))->toBeTrue();
    });

    it('does not let anyone change their own role', function () {
        roleWith('Clerk', 'User Update');
        $me = Fixtures::staffInBranch($this->branchId, 'User Update');

        $this->actingAs($me, 'api')->putJson("/api/v1/users/{$me->id}", ['name' => 'Me', 'role' => 'Clerk'])->assertForbidden();
    });

    it('lets a Super Admin assign any role', function () {
        roleWith('Auditor', 'Database Export');
        $other = Fixtures::staffInBranch($this->branchId);

        $this->actingAs(superAdmin(), 'api')->putJson("/api/v1/users/{$other->id}", ['name' => 'Other', 'role' => 'Auditor'])->assertOk();
    });

    it('lists only the roles the user may give, hiding Super Admin from everyone else', function () {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'api']);
        roleWith('Auditor', 'Database Export');
        roleWith('Clerk', 'Role Index');
        $me = staffWith('Role Index');

        $names = collect($this->actingAs($me, 'api')->getJson('/api/v1/roles/list')->json('data'))->pluck('name');
        expect($names)->toContain('Clerk')->not->toContain('Auditor')->not->toContain('Super Admin');

        $all = collect($this->actingAs(superAdmin(), 'api')->getJson('/api/v1/roles/list')->json('data'))->pluck('name');
        expect($all)->toContain('Super Admin', 'Auditor');
    });
});

describe('roles and their permissions', function () {
    it('refuses to give a role a permission its editor does not hold', function () {
        $role = roleWith('Clerk', 'Role Update', 'Role Create');
        $me = staffWith('Role Update', 'Role Create');
        $me->assignRole($role);
        $export = Permission::firstOrCreate(['name' => 'Database Export', 'guard_name' => 'api'], ['group_name' => 'T']);
        $mine = Permission::firstOrCreate(['name' => 'Role Update', 'guard_name' => 'api'], ['group_name' => 'T']);

        $this->actingAs($me, 'api')->putJson("/api/v1/roles/{$role->id}", ['permissions' => [$mine->id, $export->id]])
            ->assertForbidden()->assertJsonPath('code', 'PERMISSION_CEILING');
        $this->actingAs($me, 'api')->postJson('/api/v1/roles', ['name' => 'Copy', 'permissions' => [$export->id]])->assertForbidden();

        $this->actingAs($me, 'api')->postJson('/api/v1/roles', ['name' => 'Copy', 'permissions' => [$mine->id]])->assertCreated();
    });

    it('lets a role keep a permission its editor lacks while changing the rest', function () {
        $role = roleWith('Senior', 'Database Export', 'Role Update');
        $me = staffWith('Role Update');
        $update = Permission::where('name', 'Role Update')->first();

        $this->actingAs($me, 'api')->putJson("/api/v1/roles/{$role->id}", ['name' => 'Senior 2', 'permissions' => [$update->id, Permission::where('name', 'Database Export')->value('id')]])
            ->assertOk();
    });

    it('never lets the Super Admin role be renamed or stripped, even by a Super Admin', function () {
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'api']);

        $this->actingAs(superAdmin(), 'api')->putJson("/api/v1/roles/{$role->id}", ['name' => 'Nobody', 'permissions' => []])->assertForbidden();
        expect($role->fresh()->name)->toBe('Super Admin');
    });

    it('does not let the API mark a role as protected', function () {
        $this->actingAs(superAdmin(), 'api')->postJson('/api/v1/roles', ['name' => 'Sneaky', 'is_protected' => true, 'permissions' => [Permission::where('name', 'Role Index')->value('id')]])->assertCreated();

        expect(Role::where('name', 'Sneaky')->value('is_protected'))->toBeFalsy();
    });
});

describe('the permission list', function () {
    it('keeps the permissions the code checks: no rename, no delete, but the group can change', function () {
        $p = Permission::firstOrCreate(['name' => 'Loan Approve', 'guard_name' => 'api'], ['group_name' => 'Loan Decision Permissions']);
        $admin = superAdmin();
        $admin->givePermissionTo(['Permission Update', 'Permission Delete'] === [] ? [] : collect(['Permission Update', 'Permission Delete'])->each(fn ($n) => Permission::firstOrCreate(['name' => $n, 'guard_name' => 'api'], ['group_name' => 'T']))->all());

        $this->actingAs($admin, 'api')->putJson("/api/v1/permissions/{$p->id}", ['name' => 'Loan Approve X'])->assertStatus(422);
        $this->actingAs($admin, 'api')->deleteJson("/api/v1/permissions/{$p->id}")->assertStatus(422);
        $this->actingAs($admin, 'api')->putJson("/api/v1/permissions/{$p->id}", ['group_name' => 'Regrouped'])->assertOk();
        expect($p->fresh()->name)->toBe('Loan Approve');
    });
});

describe('bulk import', function () {
    function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('rows.csv', $content);
    }

    it('imports a table only for someone who could also create and update its records', function () {
        $onlyImport = staffWith('Bulk Import');
        $this->actingAs($onlyImport, 'api')->post('/api/v1/import/countries', ['file' => csv("name,code\nTestland,TL\n")], ['Accept' => 'application/json'])->assertForbidden();

        $allowed = staffWith('Bulk Import', 'Country Create', 'Country Update');
        $this->actingAs($allowed, 'api')->post('/api/v1/import/countries', ['file' => csv("name,code\nTestland,TL\n")], ['Accept' => 'application/json'])->assertOk();
    });

    it('lets only a Super Admin import users, and never overwrites an admin for anyone else', function () {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'api']);
        $boss = User::factory()->create(['user_type' => 'admin', 'username' => 'bossadmin']);
        $old = $boss->password;
        $row = "name,username,email,user_type,role,password\nPwned,bossadmin,boss@x.lk,admin,Super Admin,Hacked!12345\n";

        $clerk = staffWith('Bulk Import', 'User Create', 'User Update');
        $this->actingAs($clerk, 'api')->post('/api/v1/import/users', ['file' => csv($row)], ['Accept' => 'application/json'])->assertForbidden();
        expect($boss->fresh()->password)->toBe($old);
    });

    it('gives an imported user without a password a random temporary one, never the username', function () {
        $admin = superAdmin();
        $admin->givePermissionTo(collect(['Bulk Import', 'User Create', 'User Update'])->each(fn ($n) => Permission::firstOrCreate(['name' => $n, 'guard_name' => 'api'], ['group_name' => 'T']))->all());
        roleWith('Officer', 'Customer Index');

        $response = $this->actingAs($admin, 'api')->post('/api/v1/import/users', ['file' => csv("name,username,email,user_type,role\nNew Person,newperson,new@x.lk,staff,Officer\n")], ['Accept' => 'application/json'])->assertOk();

        $user = User::where('username', 'newperson')->first();
        expect($user->must_change_password)->toBeTrue()
            ->and(Hash::check('newperson', $user->password))->toBeFalse()
            ->and($response->json('data.temporary_passwords.newperson'))->not->toBeEmpty()
            ->and(Hash::check($response->json('data.temporary_passwords.newperson'), $user->password))->toBeTrue()
            ->and($user->hasRole('Officer'))->toBeTrue();
    });

    it('refuses an import row with a weak password or an unknown role', function () {
        $admin = superAdmin();
        $admin->givePermissionTo(collect(['Bulk Import', 'User Create', 'User Update'])->each(fn ($n) => Permission::firstOrCreate(['name' => $n, 'guard_name' => 'api'], ['group_name' => 'T']))->all());

        $result = $this->actingAs($admin, 'api')->post('/api/v1/import/users', ['file' => csv("name,username,email,user_type,role,password\nA,weak1,a@x.lk,staff,Nope,abc\n")], ['Accept' => 'application/json'])->assertOk();

        expect($result->json('data.failed'))->toBe(1)->and(User::where('username', 'weak1')->exists())->toBeFalse();
    });
});

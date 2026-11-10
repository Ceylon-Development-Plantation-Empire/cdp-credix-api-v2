<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\User;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Tests\Support\Fixtures;
use Spatie\Permission\Models\Role;
use Tests\Support\LoanFixtures;

/**
 * A user can be deleted for good, by permission. An open loan blocks it; other records are listed and need the sign-in name typed in
 * full; every record keeps its row and loses only the link; the audit log keeps the name and records the deletion.
 */
beforeEach(function () {
    $this->admin = userWith('admin', 'User Delete', 'User Index');
    Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'api']);
    $this->admin->assignRole('Super Admin');
});

it('deletes a user who appears nowhere without asking for the name', function () {
    $user = userWith('staff');

    $this->actingAs($this->admin, 'api')->getJson("/api/v1/users/{$user->id}/delete-impact")
        ->assertOk()->assertJsonPath('data.total', 0)->assertJsonPath('data.requires_confirmation', false)->assertJsonPath('data.can_delete', true);

    $this->actingAs($this->admin, 'api')->deleteJson("/api/v1/users/{$user->id}")->assertOk();

    expect(User::find($user->id))->toBeNull()
        ->and(ActivityLog::where('module', 'User')->where('action', 'DELETE')->exists())->toBeTrue();
});

it('lists what the user is linked to and needs the name typed in full', function () {
    $branch = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    $user = userWith('staff');
    $customer = LoanFixtures::customer($branch);
    Customer::whereKey($customer->id)->update(['created_by' => $user->id, 'updated_by' => $user->id]);

    $impact = $this->actingAs($this->admin, 'api')->getJson("/api/v1/users/{$user->id}/delete-impact")->assertOk();
    $impact->assertJsonPath('data.requires_confirmation', true)->assertJsonPath('data.confirm_text', $user->username ?: $user->email);
    expect(collect($impact->json('data.records'))->firstWhere('table', 'customers')['count'])->toBe(1);

    $this->actingAs($this->admin, 'api')->deleteJson("/api/v1/users/{$user->id}")->assertStatus(422)->assertJsonPath('code', 'USER_DELETE_CONFIRMATION_REQUIRED');
    $this->actingAs($this->admin, 'api')->deleteJson("/api/v1/users/{$user->id}", ['confirm_username' => strtoupper($user->username ?: $user->email)])->assertStatus(422);
    expect(User::find($user->id))->not->toBeNull();

    $this->actingAs($this->admin, 'api')->deleteJson("/api/v1/users/{$user->id}", ['confirm_username' => $user->username ?: $user->email])->assertOk();

    expect(User::find($user->id))->toBeNull()
        ->and(Customer::find($customer->id)->created_by)->toBeNull();
});

it('keeps the name on the audit entries of the deleted user', function () {
    $user = userWith('staff');
    ActivityLog::create(['user_id' => $user->id, 'action' => 'UPDATE', 'module' => 'Loan', 'description' => 'did something', 'level' => 'info']);

    $this->actingAs($this->admin, 'api')->deleteJson("/api/v1/users/{$user->id}", ['confirm_username' => $user->username ?: $user->email])->assertOk();

    $entry = ActivityLog::where('description', 'did something')->first();
    expect($entry->user_id)->toBeNull()->and($entry->user_name)->toContain($user->email);
});

it('refuses while the user is the officer of an open loan, and allows it once the loan is closed', function () {
    $branch = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    Fixtures::fakeSms();
    LoanFixtures::product('sme-loan');

    $user = userWith('staff');
    $loan = LoanFixtures::approvedLoan($branch, 'sme-loan', '100000', 12);
    Loan::whereKey($loan->id)->update(['assigned_officer_id' => $user->id]);

    $this->actingAs($this->admin, 'api')->getJson("/api/v1/users/{$user->id}/delete-impact")
        ->assertOk()->assertJsonPath('data.can_delete', false)->assertJsonPath('data.active_loans.0.public_id', $loan->public_id);

    $this->actingAs($this->admin, 'api')->deleteJson("/api/v1/users/{$user->id}", ['confirm_username' => $user->username ?: $user->email])
        ->assertStatus(409)->assertJsonPath('code', 'USER_HAS_ACTIVE_LOANS');
    expect(User::find($user->id))->not->toBeNull();

    Loan::whereKey($loan->id)->update(['status' => LoanStatus::Cancelled->value]);

    $this->actingAs($this->admin, 'api')->deleteJson("/api/v1/users/{$user->id}", ['confirm_username' => $user->username ?: $user->email])->assertOk();
    expect(Loan::find($loan->id)->assigned_officer_id)->toBeNull();
});

it('is decided by the permission and never lets anyone delete themselves', function () {
    $user = userWith('staff');
    $without = userWith('staff', 'User Index');
    $manager = userWith('staff', 'User Delete');

    $this->actingAs($without, 'api')->deleteJson("/api/v1/users/{$user->id}")->assertForbidden();
    $this->actingAs($without, 'api')->getJson("/api/v1/users/{$user->id}/delete-impact")->assertForbidden();
    $this->actingAs($manager, 'api')->deleteJson("/api/v1/users/{$manager->id}")->assertStatus(422);

    // Not a Super Admin, but holds the permission: may delete a staff user, not an admin account.
    $adminUser = userWith('admin');
    $this->actingAs($manager, 'api')->deleteJson("/api/v1/users/{$adminUser->id}")->assertForbidden();
    $this->actingAs($manager, 'api')->deleteJson("/api/v1/users/{$user->id}")->assertOk();
});

<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\GlJournal;
use App\Models\Loan;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

/**
 * An application that was already deleted can be deleted for good, by its own permission and with its application number typed in
 * full. Everything that belongs to it goes (guarantors, installments, payments, collateral, documents); a customer goes only when
 * nothing else uses them; the accounting entries stay as a balanced pair; a full copy is kept in the activity log.
 */
beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    Fixtures::fakeSms();
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);

    $this->boss = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Delete', 'Loan Restore', 'Loan Delete After Payout', 'Loan Delete Permanent');
    $this->cashier = Fixtures::staffInBranch($this->branchId, 'Payment Create', 'Payment Index', 'Loan Index');
});

function purgeBooksOk(): bool
{
    $recon = app(App\Services\Accounting\LedgerReconciliationService::class)->run();
    $trial = app(App\Services\Accounting\GlReportService::class)->trialBalance(CarbonImmutable::today(), null);

    return $recon['reconciled'] && $trial['balanced'];
}

it('needs the loan to be deleted first, the permission, and the application number typed in full', function () {
    $loan = LoanFixtures::approvedLoan($this->branchId, 'sme-loan', '100000', 12);
    $reader = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Delete');
    $body = ['remarks' => 'Entered by mistake', 'confirm_application_no' => $loan->application_no];

    $this->actingAs($reader, 'api')->deleteJson("/api/v1/loans/{$loan->public_id}/permanent", $body)->assertForbidden();
    $this->actingAs($this->boss, 'api')->deleteJson("/api/v1/loans/{$loan->public_id}/permanent", $body)->assertNotFound();

    $this->actingAs($this->boss, 'api')->deleteJson("/api/v1/loans/{$loan->public_id}", ['remarks' => 'Entered by mistake'])->assertOk();

    $this->actingAs($this->boss, 'api')->deleteJson("/api/v1/loans/{$loan->public_id}/permanent", [...$body, 'confirm_application_no' => 'APP-1'])
        ->assertStatus(422)->assertJsonPath('code', 'PURGE_CONFIRMATION_REQUIRED');
    expect(Loan::withTrashed()->find($loan->id))->not->toBeNull();
});

it('removes an unpaid application with its people, and its customers when nothing else uses them', function () {
    $loan = LoanFixtures::approvedLoan($this->branchId, 'sme-loan', '100000', 12);
    $customerId = $loan->customer_id;
    $this->actingAs($this->boss, 'api')->deleteJson("/api/v1/loans/{$loan->public_id}", ['remarks' => 'Entered by mistake'])->assertOk();

    $this->actingAs($this->boss, 'api')->deleteJson("/api/v1/loans/{$loan->public_id}/permanent", ['remarks' => 'Entered by mistake', 'confirm_application_no' => $loan->application_no])
        ->assertOk()->assertJsonPath('data.deleted.loans', 1);

    expect(DB::table('loans')->where('id', $loan->id)->exists())->toBeFalse()
        ->and(DB::table('loan_parties')->where('loan_id', $loan->id)->exists())->toBeFalse()
        ->and(DB::table('loan_activities')->where('loan_id', $loan->id)->exists())->toBeFalse()
        ->and(DB::table('customers')->where('id', $customerId)->exists())->toBeFalse()
        ->and(ActivityLog::where('description', 'like', "Snapshot before permanently deleting application {$loan->application_no}")->exists())->toBeTrue()
        ->and(ActivityLog::where('description', "Permanently deleted application {$loan->application_no}")->exists())->toBeTrue();
});

it('keeps a customer who is still used by another loan', function () {
    $borrower = LoanFixtures::customer($this->branchId);
    LoanFixtures::income($borrower, '150000');
    $first = LoanFixtures::approvedLoan($this->branchId, 'sme-loan', '100000', 12, $borrower);
    $this->actingAs($this->boss, 'api')->deleteJson("/api/v1/loans/{$first->public_id}", ['remarks' => 'Duplicate'])->assertOk();

    // The same person applies again while the first is in the trash.
    $second = LoanFixtures::approvedLoan($this->branchId, 'sme-loan', '80000', 12, $borrower);

    $response = $this->actingAs($this->boss, 'api')->deleteJson("/api/v1/loans/{$first->public_id}/permanent", ['remarks' => 'Duplicate', 'confirm_application_no' => $first->application_no])->assertOk();

    expect(Customer::find($borrower->id))->not->toBeNull()
        ->and(Loan::find($second->id))->not->toBeNull()
        ->and($response->json('data.customers_kept.0.customer_no'))->toBe($borrower->customer_no);
});

it('removes a paid-out loan with its installments and payments, keeps the books balanced and the reconcile passing', function () {
    $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(35));
    $this->artisan('eod:run')->assertSuccessful();
    $this->actingAs($this->cashier, 'api')->withHeader('Idempotency-Key', 'pay-'.str()->random(12))
        ->postJson('/api/v1/payments', ['loan_public_id' => $loan->public_id, 'amount' => '10000', 'channel' => 'cash'])->assertCreated();

    $this->actingAs($this->boss, 'api')->deleteJson("/api/v1/loans/{$loan->public_id}", ['remarks' => 'Wrong customer'])->assertOk();
    $journals = GlJournal::where('loan_id', $loan->id)->count();
    expect($journals)->toBeGreaterThan(0);

    $this->actingAs($this->boss, 'api')->deleteJson("/api/v1/loans/{$loan->public_id}/permanent", ['remarks' => 'Wrong customer', 'confirm_application_no' => $loan->application_no])->assertOk();

    expect(DB::table('loans')->where('id', $loan->id)->exists())->toBeFalse()
        ->and(DB::table('loan_installments')->where('loan_id', $loan->id)->exists())->toBeFalse()
        ->and(DB::table('loan_transactions')->where('loan_id', $loan->id)->exists())->toBeFalse()
        ->and(DB::table('loan_allocations')->count())->toBe(0)
        ->and(GlJournal::whereNull('loan_id')->where('source_key', 'like', 'void:%')->count())->toBeGreaterThan(0)
        ->and(purgeBooksOk())->toBeTrue();
});

it('refuses a deleted loan whose accounting was not reversed', function () {
    $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);

    // Marked as deleted behind the service's back, so its journals were never mirrored.
    Loan::whereKey($loan->id)->update(['deleted_at' => now()]);

    $this->actingAs($this->boss, 'api')->deleteJson("/api/v1/loans/{$loan->public_id}/permanent", ['remarks' => 'Cleaning up', 'confirm_application_no' => $loan->application_no])
        ->assertStatus(409)->assertJsonPath('code', 'LOAN_BOOKS_NOT_ZERO');
    expect(DB::table('loans')->where('id', $loan->id)->exists())->toBeTrue();
});

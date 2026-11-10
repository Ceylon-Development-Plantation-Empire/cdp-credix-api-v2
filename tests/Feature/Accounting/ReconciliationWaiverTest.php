<?php

declare(strict_types=1);

use App\Models\LoanTransaction;
use App\Services\Accounting\LedgerReconciliationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

/**
 * An early settlement forgives interest that was never earned. That waiver nets to zero and posts no journal by design, so the
 * nightly "every transaction has a journal" check must not raise it; a transaction that should have a journal and has none must
 * still be caught.
 */
beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    Fixtures::fakeSms();
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);
    $this->teller = Fixtures::staffInBranch($this->branchId, 'Loan Settle', 'Loan Index', 'Payment Create', 'Payment Index');
});

it('does not flag the waiver of an early settlement', function () {
    $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(10));
    $quote = $this->actingAs($this->teller, 'api')->getJson("/api/v1/loans/{$loan->public_id}/settlement-quote")->json('data');
    $this->actingAs($this->teller, 'api')->withHeader('Idempotency-Key', 'settle-recon-0001')
        ->postJson("/api/v1/loans/{$loan->public_id}/settle", ['amount' => $quote['total'], 'channel' => 'cash'])->assertCreated();

    // The settlement did book a waiver, and it has no journal.
    $waiver = LoanTransaction::where('loan_id', $loan->id)->where('type', 'waiver')->first();
    expect($waiver)->not->toBeNull()->and(DB::table('gl_journals')->where('loan_transaction_id', $waiver->id)->exists())->toBeFalse();

    $result = app(LedgerReconciliationService::class)->run();

    expect($result['checks']['transactions_have_journals'])->toBe(['ok' => true, 'problems' => []])
        ->and($result['reconciled'])->toBeTrue();
});

it('still flags a transaction that should have a journal and has none', function () {
    $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);
    $txn = $this->actingAs($this->teller, 'api')->withHeader('Idempotency-Key', 'pay-recon-0001')
        ->postJson('/api/v1/payments', ['loan_public_id' => $loan->public_id, 'amount' => '5000', 'channel' => 'cash'])->assertCreated()->json('data.public_id');

    $payment = LoanTransaction::where('public_id', $txn)->first();
    DB::table('gl_journals')->where('loan_transaction_id', $payment->id)->update(['loan_transaction_id' => null]); // what a lost journal would look like

    $check = app(LedgerReconciliationService::class)->run()['checks']['transactions_have_journals'];

    expect($check['ok'])->toBeFalse()->and($check['problems'])->toContain("Transaction {$payment->txn_no} has no GL journal");
});

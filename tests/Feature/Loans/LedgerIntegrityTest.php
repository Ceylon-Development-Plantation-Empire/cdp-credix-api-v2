<?php

declare(strict_types=1);

use App\Finance\Support\Money;
use App\Models\ActivityLog;
use App\Models\ChargeType;
use App\Models\GlJournal;
use App\Models\GlJournalLine;
use App\Models\Loan;
use App\Models\LoanActivity;
use App\Models\LoanAllocation;
use App\Models\LoanInstallment;
use App\Models\LoanTransaction;
use App\Models\Lookup;
use App\Services\Loans\LoanBalanceService;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

/*
| Money safety: idempotency, the balance trail, immutability of the ledger and the audit trail,
| and the reference data the money code depends on (audit modules A, C, E).
*/

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);
    $this->cashier = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Payment Index', 'Payment Create');
    $this->loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);
});

function postPayment($test, Loan $loan, string $amount, string $key)
{
    return $test->actingAs($test->cashier, 'api')->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/payments', ['loan_public_id' => $loan->public_id, 'amount' => $amount, 'channel' => 'cash']);
}

describe('idempotency', function () {
    it('refuses a key that was already used for a different amount', function () {
        postPayment($this, $this->loan, '1000', 'same-key-000001')->assertCreated();

        postPayment($this, $this->loan, '2500', 'same-key-000001')
            ->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        expect(LoanTransaction::where('type', 'repayment')->count())->toBe(1);
    });

    it('has the unique key as the last line of defence even if two requests race past the check', function () {
        $txn = postPayment($this, $this->loan, '1000', 'race-key-000001')->assertCreated()->json('data.public_id');
        $original = LoanTransaction::where('public_id', $txn)->firstOrFail();

        // A second insert with the same stored key (what a lost race would attempt) is refused by the database.
        expect(fn () => DB::table('loan_transactions')->insert([
            'public_id' => (string) str()->ulid(), 'loan_id' => $this->loan->id, 'type' => 'repayment', 'amount' => '1000',
            'balance_after' => '0', 'value_date' => today()->toDateString(), 'channel' => 'cash',
            'idempotency_key' => $original->getRawOriginal('idempotency_key'), 'created_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});

describe('balance trail', function () {
    it('records what was owed after every posting, so a statement needs no running sum', function () {
        $owedAfterDisbursement = LoanBalanceService::owed($this->loan);
        $disbursement = LoanTransaction::where('loan_id', $this->loan->id)->where('type', 'disbursement')->firstOrFail();

        expect($disbursement->balance_after)->toBe((string) $owedAfterDisbursement);

        $txn = postPayment($this, $this->loan, '5000', 'trail-key-00001')->assertCreated()->json('data.public_id');

        expect(LoanTransaction::where('public_id', $txn)->first()->balance_after)->toBe((string) $owedAfterDisbursement->minus('5000'))
            ->and((string) LoanBalanceService::owed($this->loan))->toBe((string) $owedAfterDisbursement->minus('5000'));
    });
});

describe('immutability', function () {
    it('never changes or deletes a posted ledger row, an allocation, a journal line, a timeline entry or an audit entry', function () {
        postPayment($this, $this->loan, '1000', 'immut-key-00001')->assertCreated();

        $txn = LoanTransaction::where('type', 'repayment')->firstOrFail();
        expect(fn () => $txn->update(['amount' => '1']))->toThrow(LogicException::class)
            ->and(fn () => $txn->delete())->toThrow(LogicException::class)
            ->and(fn () => LoanAllocation::firstOrFail()->update(['amount' => '1']))->toThrow(LogicException::class)
            ->and(fn () => GlJournalLine::firstOrFail()->delete())->toThrow(LogicException::class)
            ->and(fn () => GlJournal::where('status', 'posted')->firstOrFail()->update(['narration' => 'edited']))->toThrow(LogicException::class)
            ->and(fn () => LoanActivity::firstOrFail()->update(['remarks' => 'edited']))->toThrow(LogicException::class);

        $audit = ActivityLog::query()->firstOrFail();
        expect(fn () => $audit->update(['description' => 'covered up']))->toThrow(LogicException::class)
            ->and(fn () => $audit->delete())->toThrow(LogicException::class);

        // The amount on file is still the one that was posted.
        expect(Money::of((string) LoanTransaction::whereKey($txn->id)->value('amount'))->isEqualTo('1000'))->toBeTrue();
    });

    it('rounds money half-up to cents and keeps every rupee of a payment allocated', function () {
        $installment = LoanInstallment::where('loan_id', $this->loan->id)->orderBy('installment_no')->firstOrFail();
        $txn = postPayment($this, $this->loan, '1234.56', 'round-key-00001')->assertCreated()->json('data.public_id');

        $allocated = Money::sum(LoanAllocation::whereHas('transaction', fn ($q) => $q->where('public_id', $txn))->pluck('amount'));

        expect((string) $allocated)->toBe('1234.56')
            ->and($installment->refresh()->status->value)->toBe('partially_paid');
    });
});

describe('reconciliation', function () {
    it('proves the books after real postings, and fails loudly when a figure is tampered with', function () {
        postPayment($this, $this->loan, '7500', 'recon-key-00001')->assertCreated();
        postPayment($this, $this->loan, '1234.56', 'recon-key-00002')->assertCreated();

        $this->artisan('ledger:reconcile')->expectsOutputToContain('Ledger reconciled.')->assertSuccessful();

        // Someone edits a balance directly in the database.
        DB::table('loans')->where('id', $this->loan->id)->update(['outstanding_principal' => '1.00']);

        $this->artisan('ledger:reconcile')
            ->expectsOutputToContain('FAIL  loan_cache_matches_schedule')
            ->assertFailed();

        expect(ActivityLog::where('action', 'RECONCILIATION_FAILED')->where('level', 'critical')->exists())->toBeTrue();
    });
});

describe('reference data', function () {
    it('never deletes or recodes a lookup value, and refuses a deactivated one for new records', function () {
        $bank = Lookup::where('type', 'bank')->where('code', 'bank_of_ceylon')->firstOrFail();

        expect(fn () => $bank->delete())->toThrow(LogicException::class)
            ->and(fn () => $bank->update(['code' => 'boc']))->toThrow(LogicException::class);

        // Relabelling is fine and immediately visible (reload: the refused code change is still in memory).
        $bank = $bank->fresh();
        $bank->update(['label' => 'Bank of Ceylon (BOC)']);
        $this->actingAs(staffWith('Customer Index'), 'api')->getJson('/api/v1/customers/bank-options')->assertOk()->assertJsonFragment(['Bank of Ceylon (BOC)']);

        $bank->update(['is_active' => false]);
        $this->actingAs(staffWith('Customer Index'), 'api')->getJson('/api/v1/customers/bank-options')->assertOk()->assertJsonMissing(['Bank of Ceylon (BOC)']);
    });

    it('keeps the engine charge types: never deactivated, never recoded', function () {
        $late = ChargeType::where('code', 'late_payment')->firstOrFail();

        expect($late->is_system)->toBeTrue()
            ->and(fn () => $late->update(['is_active' => false]))->toThrow(LogicException::class)
            ->and(fn () => $late->update(['code' => 'late']))->toThrow(LogicException::class)
            ->and(fn () => $late->delete())->toThrow(LogicException::class);
    });

    it('stores short morph aliases, never PHP class names', function () {
        expect(DB::table('model_has_permissions')->value('model_type'))->toBe('user');
    });
});

<?php

use App\Finance\Support\Money;
use App\Models\GlAccount;
use App\Models\GlJournal;
use App\Models\GlJournalLine;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\RecoveryCase;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);

    $this->cashier = Fixtures::staffInBranch($this->branchId, 'Payment Create', 'Loan Index', 'Loan Write Off Request', 'Write Off Recovery Create');
    $this->supervisor = Fixtures::staffInBranch($this->branchId, 'Approval Request Index', 'Loan Write Off Approve');
    $this->accountant = staffWith('Branch Data All', 'GL Report View');
});

function nplBalance(string $slug, ?Loan $loan = null): string
{
    $lines = GlJournalLine::query()
        ->join('gl_journals', 'gl_journals.id', '=', 'gl_journal_lines.gl_journal_id')
        ->where('gl_journals.status', 'posted')
        ->when($loan, fn ($q) => $q->where('gl_journals.loan_id', $loan->id))
        ->where('gl_account_id', GlAccount::where('slug', $slug)->value('id'))
        ->get(['debit', 'credit']);

    return (string) Money::sum($lines->pluck('debit'))->minus(Money::sum($lines->pluck('credit')));
}

function nplReconciled($test): void
{
    $recon = $test->actingAs($test->accountant, 'api')->getJson('/api/v1/gl/reconciliation')->assertOk()->json('data');
    expect($recon['reconciled'])->toBeTrue(json_encode($recon['accounts']));
}

function recognisedUnpaidProfit(Loan $loan): string
{
    return (string) Money::sum(LoanInstallment::where('loan_id', $loan->id)->where('due_date', '<=', today())->get()->map(fn ($i) => Money::of($i->profit_due)->minus($i->profit_paid)));
}

describe('interest suspension', function () {
    it('moves earned but unpaid interest to suspense at 90 days and releases it once the arrears are cleared', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(130));

        // A night while the loan was still performing: installment 1's interest became income.
        Carbon::setTestNow(today()->subDays(80));
        $this->artisan('eod:run')->assertSuccessful();
        Carbon::setTestNow();
        $earned = nplBalance('interest-income');
        expect((float) $earned)->toBeLessThan(0.0);

        $this->artisan('eod:run')->assertSuccessful();
        $loan->refresh();

        // Non-performing now: everything recognised and unpaid sits in suspense, none of it in income.
        expect($loan->income_suspended_at?->toDateString())->toBe(today()->toDateString())
            ->and(nplBalance('interest-income'))->toBe('0.00')
            ->and(nplBalance('interest-suspense'))->toBe((string) Money::of(recognisedUnpaidProfit($loan))->negated())
            ->and(GlJournal::where('source', 'income_suspension')->count())->toBe(1);
        nplReconciled($this);

        // The borrower clears every rupee in arrears: collected interest becomes income again.
        $this->actingAs($this->cashier, 'api')->withHeader('Idempotency-Key', 'npl-pay-0001')
            ->postJson('/api/v1/payments', ['loan_public_id' => $loan->public_id, 'amount' => (string) $loan->arrears_amount, 'channel' => 'cash'])
            ->assertCreated();
        $this->artisan('eod:run')->assertSuccessful();

        $paidProfit = Money::sum(LoanInstallment::where('loan_id', $loan->id)->pluck('profit_paid'));
        expect($loan->refresh()->income_suspended_at)->toBeNull()
            ->and($loan->days_past_due)->toBe(0)
            ->and(nplBalance('interest-suspense'))->toBe('0.00')
            ->and(nplBalance('interest-income'))->toBe((string) $paidProfit->negated());
        nplReconciled($this);
    });
});

describe('provisioning', function () {
    it('provides by days past due once per date, and tops up as the loan ages', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(130));
        $this->artisan('eod:run')->assertSuccessful();
        $loan->refresh();

        // 91-180 days past due: 20 % of the outstanding principal.
        $this->artisan('gl:provision')->assertSuccessful();
        $this->artisan('gl:provision')->assertSuccessful();

        expect(GlJournal::where('source', 'provision')->count())->toBe(1)
            ->and(nplBalance('loan-loss-provision'))->toBe((string) Money::percentOf($loan->outstanding_principal, '20')->negated())
            ->and(nplBalance('provision-expense'))->toBe((string) Money::percentOf($loan->outstanding_principal, '20'));

        // Ninety days later it is past 180: the provision is topped up to 50 %.
        Carbon::setTestNow(today()->addDays(90));
        $this->artisan('eod:run')->assertSuccessful();
        $this->artisan('gl:provision')->assertSuccessful();
        Carbon::setTestNow();

        expect(GlJournal::where('source', 'provision')->count())->toBe(2)
            ->and(nplBalance('loan-loss-provision'))->toBe((string) Money::percentOf($loan->refresh()->outstanding_principal, '50')->negated());
    });
});

describe('write-off', function () {
    it('writes off against the provision through approval, keeps recovery going and books what comes back', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(400));
        $this->artisan('eod:run')->assertSuccessful();
        $this->artisan('gl:provision')->assertSuccessful();
        $loan->refresh();

        expect($loan->days_past_due)->toBeGreaterThan(360)
            ->and(nplBalance('loan-loss-provision'))->toBe('-120000.00');

        $approval = $this->actingAs($this->cashier, 'api')->postJson("/api/v1/loans/{$loan->public_id}/write-off", ['remarks' => 'Borrower absconded, legal action under way'])
            ->assertStatus(202)->assertJsonPath('data.action', 'loan_write_off')->json('data.public_id');

        $this->actingAs($this->supervisor, 'api')->postJson("/api/v1/approval-requests/{$approval}/approve", ['remarks' => 'Board minute 14/2026'])->assertOk();
        $loan->refresh();

        // Off the books, paid for by the provision; nothing left owed in the sub-ledger.
        expect($loan->status->value)->toBe('written_off')
            ->and($loan->outstanding_principal)->toBe('0.00')
            ->and($loan->income_suspended_at)->toBeNull()
            ->and(nplBalance('loans-receivable'))->toBe('0.00')
            ->and(nplBalance('interest-receivable'))->toBe('0.00')
            ->and(nplBalance('penalty-receivable'))->toBe('0.00')
            ->and(nplBalance('interest-suspense'))->toBe('0.00')
            ->and(nplBalance('loan-loss-provision'))->toBe('0.00')
            ->and(nplBalance('loans-written-off'))->toBe('0.00')
            ->and(nplBalance('interest-income'))->toBe('0.00');
        nplReconciled($this);

        // Collection goes on: the case stays open and the borrower and collateral are not released.
        $party = $loan->parties()->first();
        expect(RecoveryCase::where('loan_id', $loan->id)->open()->exists())->toBeTrue()
            ->and($party->open_loan_key)->not->toBeNull()
            ->and($party->released_at)->toBeNull();

        $writtenOff = $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$loan->public_id}/write-off")->assertOk()->json('data.written_off');

        $recover = fn (string $amount, string $key) => $this->actingAs($this->cashier, 'api')->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/loans/{$loan->public_id}/recoveries", ['amount' => $amount, 'channel' => 'cash']);

        $recover('10000', 'recover-0001')->assertCreated()->assertJsonPath('data.type', 'recovery');
        $recover('10000', 'recover-0001')->assertOk();
        expect(nplBalance('bad-debt-recovered'))->toBe('-10000.00')
            ->and(nplBalance('cash-in-hand'))->toBe('-110000.00');

        $rest = (string) Money::of($writtenOff)->minus('10000');
        $recover((string) Money::of($rest)->plus('1'), 'recover-0002')->assertStatus(422)->assertJsonPath('code', 'RECOVERY_EXCEEDS_BALANCE');
        $recover($rest, 'recover-0003')->assertCreated();

        // Recovered in full: now the securities go.
        expect($party->fresh()->released_at)->not->toBeNull()
            ->and($party->fresh()->open_loan_key)->toBeNull();
    });

    it('refuses a write-off before the loan is far enough behind', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(130));
        $this->artisan('eod:run')->assertSuccessful();

        $this->actingAs($this->cashier, 'api')->postJson("/api/v1/loans/{$loan->public_id}/write-off", ['remarks' => 'Too soon'])
            ->assertStatus(422)->assertJsonPath('code', 'WRITE_OFF_TOO_EARLY');

        $this->actingAs($this->cashier, 'api')->withHeader('Idempotency-Key', 'recover-0009')
            ->postJson("/api/v1/loans/{$loan->public_id}/recoveries", ['amount' => '100', 'channel' => 'cash'])
            ->assertStatus(409)->assertJsonPath('code', 'LOAN_NOT_WRITTEN_OFF');
    });
});

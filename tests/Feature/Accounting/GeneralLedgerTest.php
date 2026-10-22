<?php

declare(strict_types=1);

use App\Finance\Support\Money;
use App\Models\GlAccount;
use App\Models\GlJournal;
use App\Models\GlJournalLine;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanTransaction;
use App\Models\Setting;
use App\Services\Accounting\GlReportService;
use App\Services\Settings\SettingService;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Validation\ValidationException;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);

    $this->cashier = Fixtures::staffInBranch($this->branchId, 'Payment Create', 'Payment Reversal Request', 'Loan Settle', 'Loan Restructure Request', 'Loan Index');
    $this->supervisor = Fixtures::staffInBranch($this->branchId, 'Approval Request Index', 'Payment Reversal Approve', 'Loan Restructure Approve', 'GL Journal Approve');
    $this->accountant = staffWith('Branch Data All', 'GL Account Index', 'GL Account Manage', 'GL Journal Index', 'GL Journal Request', 'GL Report View', 'GL Period Close');
});

/**
 * Debit-positive balance of a system account over every posted journal.
 */
function glBalance(string $slug): string
{
    $lines = GlJournalLine::query()
        ->join('gl_journals', 'gl_journals.id', '=', 'gl_journal_lines.gl_journal_id')
        ->where('gl_journals.status', 'posted')
        ->where('gl_account_id', GlAccount::where('slug', $slug)->value('id'))
        ->get(['debit', 'credit']);

    return (string) Money::sum($lines->pluck('debit'))->minus(Money::sum($lines->pluck('credit')));
}

function glPay($test, Loan $loan, string $amount, string $channel = 'cash')
{
    return $test->actingAs($test->cashier, 'api')
        ->withHeader('Idempotency-Key', 'pay-'.str()->random(12))
        ->postJson('/api/v1/payments', ['loan_public_id' => $loan->public_id, 'amount' => $amount, 'channel' => $channel]
            + ($channel === 'cash' ? [] : ['external_reference' => 'REF-'.str()->random(8)]));
}

function assertReconciled($test): void
{
    $recon = $test->actingAs($test->accountant, 'api')->getJson('/api/v1/gl/reconciliation')->assertOk()->json('data');

    expect($recon['reconciled'])->toBeTrue(json_encode($recon['accounts']));
}

describe('posting rules', function () {
    it('posts the disbursement, the interest when due and the repayment, and stays in step with the loans', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(35));

        $disbursement = GlJournal::where('source', 'loan_transaction')->where('loan_id', $loan->id)->first();
        expect($disbursement->status->value)->toBe('posted')
            ->and($disbursement->branch_id)->toBe($this->branchId)
            ->and($disbursement->narration)->toContain($loan->loan_no)
            ->and(glBalance('loans-receivable'))->toBe('120000.00')
            ->and(glBalance('cash-in-hand'))->toBe('-120000.00');

        // Interest is income on the due date, posted once however often the night runs.
        $this->artisan('eod:run')->assertSuccessful();
        $this->artisan('eod:run')->assertSuccessful();

        $first = LoanInstallment::where('loan_id', $loan->id)->orderBy('installment_no')->first();
        expect(GlJournal::where('source', 'installment_due')->count())->toBe(1)
            ->and(glBalance('interest-receivable'))->toBe((string) $first->profit_due)
            ->and(glBalance('interest-income'))->toBe((string) Money::of($first->profit_due)->negated());

        glPay($this, $loan, (string) Money::of($first->principal_due)->plus($first->profit_due))->assertCreated();

        expect(glBalance('interest-receivable'))->toBe('0.00')
            ->and(glBalance('loans-receivable'))->toBe((string) Money::of('120000')->minus($first->principal_due))
            ->and(glBalance('cash-in-hand'))->toBe((string) Money::of('-120000')->plus($first->principal_due)->plus($first->profit_due));

        $tb = app(GlReportService::class)->trialBalance(CarbonImmutable::today(), null);
        expect($tb['balanced'])->toBeTrue();
        assertReconciled($this);
    });

    it('takes a reversed payment back out of the ledger without touching the original journal', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);
        $txn = glPay($this, $loan, '5000', 'bank_transfer')->assertCreated()->json('data.public_id');

        expect(glBalance('bank'))->toBe('5000.00');
        $original = GlJournal::where('source', 'loan_transaction')->latest('id')->first();

        $approval = $this->actingAs($this->cashier, 'api')->postJson("/api/v1/payments/{$txn}/reverse", ['remarks' => 'Transfer recalled'])->assertStatus(202)->json('data.public_id');
        $this->actingAs($this->supervisor, 'api')->postJson("/api/v1/approval-requests/{$approval}/approve", ['remarks' => 'Checked'])->assertOk();

        expect(glBalance('bank'))->toBe('0.00')
            ->and(glBalance('loans-receivable'))->toBe('120000.00')
            ->and($original->fresh()->lines()->sum('debit'))->toEqual('5000.00');
        assertReconciled($this);
    });

    it('books only the interest actually charged on early settlement', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(10));
        $quote = $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$loan->public_id}/settlement-quote")->json('data');

        $this->actingAs($this->cashier, 'api')->withHeader('Idempotency-Key', 'settle-gl-0001')
            ->postJson("/api/v1/loans/{$loan->public_id}/settle", ['amount' => $quote['total'], 'channel' => 'cash'])
            ->assertCreated();

        // The unearned interest was never income, so forgiving it posts nothing.
        $waiver = LoanTransaction::where('loan_id', $loan->id)->where('type', 'waiver')->value('id');
        expect($waiver)->not->toBeNull()
            ->and(GlJournal::where('loan_transaction_id', $waiver)->exists())->toBeFalse()
            ->and(glBalance('loans-receivable'))->toBe('0.00')
            ->and(glBalance('interest-income'))->toBe((string) Money::of($quote['profit_payable'])->negated())
            ->and(glBalance('cash-in-hand'))->toBe((string) Money::of($quote['total'])->minus('120000'));
        assertReconciled($this);
    });

    it('keeps Islamic financing apart and sends late charges to charity, never to income', function () {
        LoanFixtures::murabahaProduct();
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'murabaha-asset-finance', '100000', 12, disbursedOn: CarbonImmutable::today()->subDays(45));

        $this->artisan('eod:run')->assertSuccessful();
        $loan->refresh();

        expect(glBalance('islamic-financing-receivable'))->toBe('100000.00')
            ->and(glBalance('loans-receivable'))->toBe('0.00')
            ->and((float) glBalance('profit-receivable'))->toBeGreaterThan(0.0)
            ->and(glBalance('interest-income'))->toBe('0.00')
            ->and((float) $loan->outstanding_penalty)->toBeGreaterThan(0.0)
            ->and(glBalance('charity-payable'))->toBe((string) Money::of($loan->outstanding_penalty)->negated())
            ->and(glBalance('penalty-income'))->toBe('0.00');
        assertReconciled($this);
    });

    it('moves capitalised interest into principal on a restructure', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(40));
        $this->artisan('eod:run')->assertSuccessful();

        $approval = $this->actingAs($this->cashier, 'api')->postJson("/api/v1/loans/{$loan->public_id}/restructure", ['tenure' => 24, 'rate' => '16', 'remarks' => 'Income dropped after flood'])
            ->assertStatus(202)->json('data.public_id');
        $this->actingAs($this->supervisor, 'api')->postJson("/api/v1/approval-requests/{$approval}/approve", ['remarks' => 'Checked'])->assertOk();

        expect(GlJournal::where('source', 'restructure')->count())->toBe(1)
            ->and(glBalance('loans-receivable'))->toBe((string) $loan->refresh()->outstanding_principal)
            ->and(glBalance('interest-receivable'))->toBe('0.00');
        assertReconciled($this);
    });
});

describe('manual journals', function () {
    beforeEach(function () {
        $this->cash = GlAccount::where('slug', 'cash-in-hand')->value('id');
        $this->bank = GlAccount::where('slug', 'bank')->value('id');
    });

    it('posts only after a second person approves it', function () {
        $payload = ['value_date' => today()->toDateString(), 'narration' => 'Cash float from bank', 'lines' => [
            ['gl_account_id' => $this->cash, 'debit' => '25000'],
            ['gl_account_id' => $this->bank, 'credit' => '25000'],
        ]];

        $approval = $this->actingAs($this->accountant, 'api')->postJson('/api/v1/gl/journals', $payload)
            ->assertStatus(202)->assertJsonPath('data.subject.type', 'journal')->json('data.public_id');

        expect(glBalance('cash-in-hand'))->toBe('0.00');

        // The person who entered it cannot approve it, even with the permission.
        $this->accountant->givePermissionTo(['GL Journal Approve', 'Approval Request Index']);
        $this->actingAs($this->accountant->fresh(), 'api')->postJson("/api/v1/approval-requests/{$approval}/approve")
            ->assertStatus(403)->assertJsonPath('code', 'SELF_APPROVAL_NOT_ALLOWED');

        $headOffice = staffWith('Branch Data All', 'Approval Request Index', 'GL Journal Approve');
        $this->actingAs($headOffice, 'api')->postJson("/api/v1/approval-requests/{$approval}/approve", ['remarks' => 'OK'])->assertOk();

        expect(glBalance('cash-in-hand'))->toBe('25000.00')->and(glBalance('bank'))->toBe('-25000.00');
        $journal = GlJournal::where('source', 'manual')->first();
        expect($journal->status->value)->toBe('posted')->and($journal->approved_by)->toBe($headOffice->id);

        // Posted means final.
        expect(fn () => $journal->update(['narration' => 'Changed']))->toThrow(LogicException::class);
    });

    it('refuses unbalanced journals and loan control accounts', function () {
        $this->actingAs($this->accountant, 'api')->postJson('/api/v1/gl/journals', ['value_date' => today()->toDateString(), 'narration' => 'Typo', 'lines' => [
            ['gl_account_id' => $this->cash, 'debit' => '100'],
            ['gl_account_id' => $this->bank, 'credit' => '90'],
        ]])->assertStatus(422)->assertJsonPath('code', 'JOURNAL_NOT_BALANCED');

        $this->actingAs($this->accountant, 'api')->postJson('/api/v1/gl/journals', ['value_date' => today()->toDateString(), 'narration' => 'Fix the loan book', 'lines' => [
            ['gl_account_id' => GlAccount::where('slug', 'loans-receivable')->value('id'), 'debit' => '100'],
            ['gl_account_id' => $this->bank, 'credit' => '100'],
        ]])->assertStatus(422)->assertJsonPath('code', 'CONTROL_ACCOUNT');

        expect(GlJournal::count())->toBe(0);
    });

    it('closes the books: no entries inside the period, late loan movements land on the first open day', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subMonth()->subDays(10));
        $through = today()->subDays(5)->toDateString();

        $this->actingAs($this->accountant, 'api')->postJson('/api/v1/gl/period/close', ['through' => today()->toDateString()])
            ->assertStatus(422)->assertJsonPath('code', 'PERIOD_NOT_ENDED');
        $this->actingAs($this->accountant, 'api')->postJson('/api/v1/gl/period/close', ['through' => $through])->assertOk();
        $this->actingAs($this->accountant, 'api')->getJson('/api/v1/gl/period')->assertJsonPath('data.closed_through', $through);

        $this->actingAs($this->accountant, 'api')->postJson('/api/v1/gl/journals', ['value_date' => $through, 'narration' => 'Too late', 'lines' => [
            ['gl_account_id' => $this->cash, 'debit' => '100'],
            ['gl_account_id' => $this->bank, 'credit' => '100'],
        ]])->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');

        // The settings screen cannot reopen the books.
        $setting = Setting::where('key', 'gl.closed_through')->first();
        expect(fn () => app(SettingService::class)->update($setting, '2000-01-01', $this->accountant))
            ->toThrow(ValidationException::class);

        // Interest of the installment due 10 days ago (inside the closed period) is entered on the first open day.
        $this->artisan('eod:run')->assertSuccessful();
        $due = GlJournal::where('source', 'installment_due')->first();

        expect($due->value_date->toDateString())->toBe(LoanInstallment::where('loan_id', $loan->id)->orderBy('installment_no')->value('due_date')->toDateString())
            ->and($due->entry_date->toDateString())->toBe(CarbonImmutable::parse($through)->addDay()->toDateString());
    });
});

describe('chart of accounts and reports', function () {
    it('lets system accounts be renamed but never retyped or switched off', function () {
        $account = GlAccount::where('slug', 'cash-in-hand')->first();

        $this->actingAs($this->accountant, 'api')->putJson("/api/v1/gl/accounts/{$account->id}", ['name' => 'Cash in vault', 'code' => '1001'])
            ->assertOk()->assertJsonPath('data.name', 'Cash in vault')->assertJsonPath('data.is_system', true);

        $this->actingAs($this->accountant, 'api')->putJson("/api/v1/gl/accounts/{$account->id}", ['is_active' => false])
            ->assertStatus(422)->assertJsonPath('code', 'SYSTEM_ACCOUNT');
        $this->actingAs($this->accountant, 'api')->putJson("/api/v1/gl/accounts/{$account->id}", ['type' => 'expense'])
            ->assertStatus(422)->assertJsonPath('code', 'SYSTEM_ACCOUNT');

        $this->actingAs($this->accountant, 'api')->postJson('/api/v1/gl/accounts', ['code' => '6100', 'name' => 'Staff welfare', 'type' => 'expense'])
            ->assertCreated()->assertJsonPath('data.is_system', false);
        $this->actingAs($this->accountant, 'api')->postJson('/api/v1/gl/accounts', ['code' => '6100', 'name' => 'Duplicate', 'type' => 'expense'])
            ->assertStatus(422);
    });

    it('shows a branch user their own branch only', function () {
        $kandy = Fixtures::branch('Kandy');
        LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);
        LoanFixtures::disbursedLoan($kandy, 'sme-loan', '50000', 12);
        $kandyAccountant = Fixtures::staffInBranch($kandy, 'GL Report View', 'GL Journal Index');

        $all = $this->actingAs($this->accountant, 'api')->getJson('/api/v1/gl/trial-balance')->assertOk()->json('data');
        $own = $this->actingAs($kandyAccountant, 'api')->getJson("/api/v1/gl/trial-balance?branch_id={$this->branchId}")->assertOk()->json('data');

        $receivable = fn (array $tb) => collect($tb['accounts'])->firstWhere('account.code', '1100')['debit'];
        expect($receivable($all))->toBe('170000.00')
            ->and($own['branch_id'])->toBe($kandy)
            ->and($receivable($own))->toBe('50000.00')
            ->and($own['balanced'])->toBeTrue();

        $this->actingAs($kandyAccountant, 'api')->getJson('/api/v1/gl/journals')->assertOk()->assertJsonPath('data.meta.total', 1);
    });

    it('gives an account statement and a CSV export safe to open in a spreadsheet', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);
        glPay($this, $loan, '5000')->assertCreated();

        $cash = GlAccount::where('slug', 'cash-in-hand')->first();
        $statement = $this->actingAs($this->accountant, 'api')->getJson("/api/v1/gl/accounts/{$cash->id}/statement")->assertOk()->json('data');

        expect($statement['opening_balance'])->toBe('0.00')
            ->and($statement['total_credit'])->toBe('120000.00')
            ->and($statement['total_debit'])->toBe('5000.00')
            ->and($statement['closing_balance'])->toBe('-115000.00')
            ->and($statement['items'])->toHaveCount(2);

        $approver = staffWith('Branch Data All', 'Approval Request Index', 'GL Journal Approve');
        $approval = $this->actingAs($this->accountant, 'api')->postJson('/api/v1/gl/journals', ['value_date' => today()->toDateString(), 'narration' => '=HYPERLINK("http://evil")', 'lines' => [
            ['gl_account_id' => $cash->id, 'debit' => '10'],
            ['gl_account_id' => GlAccount::where('slug', 'bank')->value('id'), 'credit' => '10'],
        ]])->json('data.public_id');
        $this->actingAs($approver, 'api')->postJson("/api/v1/approval-requests/{$approval}/approve")->assertOk();

        $csv = $this->actingAs($this->accountant, 'api')->get('/api/v1/gl/journals/export?from='.today()->toDateString().'&to='.today()->toDateString())
            ->assertOk()->streamedContent();

        expect($csv)->toStartWith('journal_no,entry_date')
            ->toContain($loan->loan_no)
            ->toContain("'=HYPERLINK")
            ->not->toContain(',=HYPERLINK');
    });
});

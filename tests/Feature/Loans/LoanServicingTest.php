<?php

declare(strict_types=1);

use App\Finance\Support\Money;
use App\Models\ApprovalRequest;
use App\Models\Loan;
use App\Models\LoanAllocation;
use App\Models\LoanInstallment;
use App\Models\LoanParty;
use App\Models\LoanTransaction;
use App\Services\Loans\EligibilityService;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Spatie\Permission\Models\Permission;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);

    $this->cashier = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Payment Index', 'Payment Create', 'Payment Reversal Request', 'Penalty Waiver Request', 'Loan Restructure Request', 'Loan Settle', 'Approval Request Index');
    $this->supervisor = Fixtures::staffInBranch($this->branchId, 'Approval Request Index', 'Payment Reversal Approve', 'Payment Backdate Approve', 'Penalty Waiver Approve', 'Loan Restructure Approve');
});

function pay($test, Loan $loan, string $amount, array $extra = [], ?string $key = null)
{
    return $test->actingAs($test->cashier, 'api')
        ->withHeader('Idempotency-Key', $key ?? 'pay-'.str()->random(12))
        ->postJson('/api/v1/payments', array_merge(['loan_public_id' => $loan->public_id, 'amount' => $amount, 'channel' => 'cash'], $extra));
}

function approveRequest($test, string $publicId)
{
    return $test->actingAs($test->supervisor, 'api')->postJson("/api/v1/approval-requests/{$publicId}/approve", ['remarks' => 'Checked']);
}

function firstInstallment(Loan $loan): LoanInstallment
{
    return LoanInstallment::where('loan_id', $loan->id)->orderBy('installment_no')->first();
}

describe('repayments', function () {
    beforeEach(function () {
        $this->loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);
    });

    it('settles the oldest installment first and never posts twice', function () {
        $installment = firstInstallment($this->loan);
        $amount = (string) $installment->total_due;

        $txn = pay($this, $this->loan, $amount, [], 'pay-key-000001')->assertCreated()
            ->assertJsonPath('data.type', 'repayment')
            ->assertJsonPath('data.allocations.0.installment_no', 1)
            ->json('data.public_id');

        $allocated = Money::sum(LoanAllocation::whereHas('transaction', fn ($q) => $q->where('public_id', $txn))->pluck('amount'));

        expect($installment->refresh()->status->value)->toBe('paid')
            ->and((string) $allocated)->toBe((string) Money::of($amount));

        // Same key: the first result comes back, nothing new is posted.
        pay($this, $this->loan, $amount, [], 'pay-key-000001')->assertOk()->assertJsonPath('data.public_id', $txn);
        expect(LoanTransaction::where('type', 'repayment')->count())->toBe(1);

        expect($this->loan->refresh()->outstanding_principal)->toBe((string) Money::of('120000')->minus($installment->principal_due));
    });

    it('refuses a payment above what is owed and one without an idempotency key', function () {
        pay($this, $this->loan, '500000')->assertStatus(422)->assertJsonPath('code', 'PAYMENT_EXCEEDS_BALANCE');

        $this->flushHeaders()->actingAs($this->cashier, 'api')->postJson('/api/v1/payments', ['loan_public_id' => $this->loan->public_id, 'amount' => '100', 'channel' => 'cash'])
            ->assertStatus(422)->assertJsonFragment(['field' => 'idempotency_key']);
    });

    it('closes the loan when the last rupee is paid and frees the borrower', function () {
        $owed = (string) Money::sum(LoanInstallment::where('loan_id', $this->loan->id)->pluck('total_due'));

        pay($this, $this->loan, $owed)->assertCreated();

        expect($this->loan->refresh()->status->value)->toBe('settled')
            ->and($this->loan->closure_reason->value)->toBe('settled')
            ->and(LoanParty::where('loan_id', $this->loan->id)->value('open_loan_key'))->toBeNull();
    });

    it('marks a guarantor who pays as invoked, and clears it when that payment is reversed (G-3)', function () {
        $guarantor = LoanFixtures::customer($this->branchId);
        $this->loan->parties()->create(['customer_id' => $guarantor->id, 'role' => 'guarantor']);

        $txn = pay($this, $this->loan, '5000', ['payer_customer_public_id' => $guarantor->public_id])->assertCreated()->json('data.public_id');

        expect(LoanParty::where('customer_id', $guarantor->id)->value('invoked_at'))->not->toBeNull()
            ->and(app(EligibilityService::class)->isBlockedGuarantor($guarantor))->toBeTrue();

        $approval = $this->actingAs($this->cashier, 'api')->postJson("/api/v1/payments/{$txn}/reverse", ['remarks' => 'Cheque returned'])->assertStatus(202)->json('data.public_id');
        approveRequest($this, $approval)->assertOk();

        expect(LoanParty::where('customer_id', $guarantor->id)->value('invoked_at'))->toBeNull();
    });

    it('refuses a payer who is not on the loan', function () {
        pay($this, $this->loan, '5000', ['payer_customer_public_id' => LoanFixtures::customer($this->branchId)->public_id])
            ->assertStatus(422)->assertJsonPath('code', 'PAYER_NOT_PARTY');
    });

    it('hides payments of other branches', function () {
        $txn = pay($this, $this->loan, '5000')->json('data.public_id');
        $kandy = Fixtures::staffInBranch(Fixtures::branch('Kandy'), 'Payment Index');

        $this->actingAs($kandy, 'api')->getJson("/api/v1/payments/{$txn}")->assertNotFound();
        $this->actingAs($kandy, 'api')->getJson('/api/v1/payments')->assertOk()->assertJsonPath('data.meta.total', 0);
    });
});

describe('reversals', function () {
    it('reverses a payment only with a second person, once, without touching the original', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);
        $txn = pay($this, $loan, '5000')->json('data.public_id');
        $paidBefore = (string) firstInstallment($loan)->total_paid;

        $approval = $this->actingAs($this->cashier, 'api')->postJson("/api/v1/payments/{$txn}/reverse", ['remarks' => 'Posted to the wrong loan'])
            ->assertStatus(202)->assertJsonPath('data.action', 'payment_reversal')->json('data.public_id');

        // The person who asked cannot decide.
        $this->cashier->givePermissionTo(Permission::firstOrCreate(['name' => 'Payment Reversal Approve', 'guard_name' => 'api'], ['group_name' => 'Tests']));
        $this->actingAs($this->cashier->fresh(), 'api')->postJson("/api/v1/approval-requests/{$approval}/approve")
            ->assertStatus(403)->assertJsonPath('code', 'SELF_APPROVAL_NOT_ALLOWED');

        approveRequest($this, $approval)->assertOk()->assertJsonPath('data.status', 'approved');

        $original = LoanTransaction::where('public_id', $txn)->first();
        expect($paidBefore)->toBe('5000.00')
            ->and((string) firstInstallment($loan)->total_paid)->toBe('0.00')
            ->and($original->amount)->toBe('5000.00')
            ->and(LoanTransaction::where('type', 'reversal')->where('reverses_transaction_id', $original->id)->count())->toBe(1)
            ->and((string) LoanAllocation::whereHas('transaction', fn ($q) => $q->where('loan_id', $loan->id))->sum('amount'))->toBe('0');

        $this->actingAs($this->cashier->fresh(), 'api')->postJson("/api/v1/payments/{$txn}/reverse", ['remarks' => 'Again'])
            ->assertStatus(409)->assertJsonPath('code', 'ALREADY_REVERSED');
    });

    it('keeps approval requests inside their branch', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);
        $txn = pay($this, $loan, '5000')->json('data.public_id');
        $approval = $this->actingAs($this->cashier, 'api')->postJson("/api/v1/payments/{$txn}/reverse", ['remarks' => 'Wrong loan'])->json('data.public_id');

        $kandySupervisor = Fixtures::staffInBranch(Fixtures::branch('Kandy'), 'Approval Request Index', 'Payment Reversal Approve');

        $this->actingAs($kandySupervisor, 'api')->getJson('/api/v1/approval-requests')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->actingAs($kandySupervisor, 'api')->postJson("/api/v1/approval-requests/{$approval}/approve")->assertNotFound();
    });

    it('sends a payment dated too far back for approval and posts it when approved', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(20));

        $approval = pay($this, $loan, '5000', ['value_date' => today()->subDays(3)->toDateString()], 'pay-backdate-01')
            ->assertStatus(202)->assertJsonPath('data.action', 'backdated_payment')->json('data.public_id');

        expect(LoanTransaction::where('type', 'repayment')->count())->toBe(0);

        approveRequest($this, $approval)->assertOk();

        $posted = LoanTransaction::where('type', 'repayment')->first();
        expect($posted->value_date->toDateString())->toBe(today()->subDays(3)->toDateString())
            ->and($posted->approved_by)->toBe($this->supervisor->id);
    });
});

describe('end of day', function () {
    it('marks installments overdue, raises late charges once per date and grades the arrears', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(100));

        $this->artisan('eod:run')->assertSuccessful();

        $loan->refresh();
        $first = firstInstallment($loan);
        $penalties = LoanTransaction::where('loan_id', $loan->id)->where('type', 'penalty')->count();

        expect($first->status->value)->toBe('overdue')
            ->and($penalties)->toBeGreaterThan(0)
            ->and($loan->days_past_due)->toBe((int) $first->due_date->diffInDays(today()))
            ->and($loan->risk_stage)->toBe(2)
            ->and((float) $loan->arrears_amount)->toBeGreaterThan(0.0)
            ->and((float) $loan->outstanding_penalty)->toBeGreaterThan(0.0);

        // 2% of the unpaid installment (penalty never on penalty).
        $expected = Money::percentOf(Money::of($first->principal_due)->plus($first->profit_due), '2');
        expect(LoanTransaction::where('loan_id', $loan->id)->where('type', 'penalty')->value('amount'))->toBe((string) $expected);

        // Running the same day again charges nothing new.
        $this->artisan('eod:run')->assertSuccessful();
        expect(LoanTransaction::where('loan_id', $loan->id)->where('type', 'penalty')->count())->toBe($penalties);
    });

    it('waives a late charge only through approval', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(45));
        $this->artisan('eod:run')->assertSuccessful();

        $penalty = (string) firstInstallment($loan)->penalty_due;

        $this->actingAs($this->cashier, 'api')->postJson("/api/v1/loans/{$loan->public_id}/installments/1/penalty-waiver", ['amount' => '999999', 'remarks' => 'Hospitalised'])
            ->assertStatus(422)->assertJsonPath('code', 'WAIVER_EXCEEDS_PENALTY');

        $approval = $this->actingAs($this->cashier, 'api')->postJson("/api/v1/loans/{$loan->public_id}/installments/1/penalty-waiver", ['amount' => $penalty, 'remarks' => 'Hospitalised'])
            ->assertStatus(202)->json('data.public_id');

        expect(firstInstallment($loan)->penalty_waived)->toBe('0.00');

        approveRequest($this, $approval)->assertOk();

        expect(firstInstallment($loan)->penalty_waived)->toBe($penalty)
            ->and($loan->refresh()->outstanding_penalty)->toBe('0.00');
    });
});

describe('early settlement', function () {
    it('charges accrued interest only and records the rest as not charged', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(10));

        $quote = $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$loan->public_id}/settlement-quote")->assertOk()->json('data');

        // 10 days of interest on 120,000 at 18% (actual/365) = 591.78.
        expect($quote['future_principal'])->toBe('120000.00')
            ->and($quote['profit_payable'])->toBe('591.78')
            ->and($quote['total'])->toBe('120591.78');

        $this->actingAs($this->cashier, 'api')->withHeader('Idempotency-Key', 'settle-0001')
            ->postJson("/api/v1/loans/{$loan->public_id}/settle", ['amount' => '120000', 'channel' => 'cash'])
            ->assertStatus(422)->assertJsonPath('code', 'SETTLEMENT_AMOUNT_MISMATCH');

        $this->actingAs($this->cashier, 'api')->withHeader('Idempotency-Key', 'settle-0001')
            ->postJson("/api/v1/loans/{$loan->public_id}/settle", ['amount' => $quote['total'], 'channel' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.loan.status', 'settled')
            ->assertJsonPath('data.loan.closure_reason', 'early_settled');

        expect(LoanTransaction::where('loan_id', $loan->id)->where('type', 'waiver')->value('amount'))->toBe($quote['profit_forgiven'])
            ->and(LoanInstallment::where('loan_id', $loan->id)->get()->every(fn ($i) => $i->status->value === 'paid'))->toBeTrue();
    });

    it('gives the Shariah-approved rebate on unearned profit for Murabaha', function () {
        LoanFixtures::murabahaProduct();
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'murabaha-asset-finance', '100000', 12, disbursedOn: CarbonImmutable::today()->subDays(10));

        $futureProfit = (string) $loan->profit_amount;
        $quote = $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$loan->public_id}/settlement-quote")->assertOk()->json('data');

        expect($quote['cost_label'])->toBe('Profit')
            ->and($quote['fee'])->toBe('0.00')
            ->and($quote['profit_forgiven'])->toBe((string) Money::percentOf($futureProfit, '50'))
            ->and($quote['total'])->toBe((string) Money::of('100000')->plus($futureProfit)->minus($quote['profit_forgiven']));
    });
});

describe('restructure', function () {
    it('writes a new schedule version after approval and keeps the old lines as history', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(40));
        $this->artisan('eod:run')->assertSuccessful();
        $loan->refresh();
        $penaltyBefore = $loan->outstanding_penalty;
        $principalBefore = $loan->outstanding_principal;

        $approval = $this->actingAs($this->cashier, 'api')->postJson("/api/v1/loans/{$loan->public_id}/restructure", ['tenure' => 24, 'rate' => '16', 'remarks' => 'Income dropped after flood'])
            ->assertStatus(202)->json('data.public_id');

        // No payments while the restructure waits.
        pay($this, $loan, '1000')->assertStatus(409)->assertJsonPath('code', 'RESTRUCTURE_PENDING');

        approveRequest($this, $approval)->assertOk();

        $loan->refresh();
        $new = LoanInstallment::where('loan_id', $loan->id)->where('schedule_version', 2)->orderBy('installment_no')->get();

        expect($new)->toHaveCount(24)
            ->and(LoanInstallment::where('loan_id', $loan->id)->where('schedule_version', 1)->where('status', 'rescheduled')->count())->toBe(12)
            ->and($loan->tenure)->toBe(24)
            ->and($loan->rate)->toBe('16.0000')
            // Unpaid late charges move to the first new line instead of being capitalised.
            ->and($new->first()->penalty_due)->toBe($penaltyBefore)
            ->and($loan->outstanding_penalty)->toBe($penaltyBefore)
            // The overdue interest is capitalised, so the new principal is at least the old one.
            ->and((float) $loan->outstanding_principal)->toBeGreaterThan((float) $principalBefore);

        expect(ApprovalRequest::where('action', 'restructure')->value('status')->value)->toBe('approved');
        pay($this, $loan, '1000')->assertCreated();
    });
});

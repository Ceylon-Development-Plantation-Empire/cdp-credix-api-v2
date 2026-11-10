<?php

declare(strict_types=1);

use App\Models\Collateral;
use App\Models\Loan;
use App\Models\LoanActivity;
use App\Models\LoanParty;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

/**
 * An application can be edited and deleted at every stage before it is paid out; who may is decided by the permission.
 * After submission a change needs a reason, and a change to the money terms sends the application back to be
 * reviewed again (the decisions were given on the old figures).
 */
beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    Fixtures::fakeSms();
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);

    $this->officer = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Create', 'Loan Update', 'Loan Delete', 'Loan Restore', 'Loan Submit', 'Loan Cancel', 'Loan Offer Manage', 'Loan Collateral Create');
    $this->reviewer = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Review');
    $this->verifier = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Verify', 'Loan Collateral Verify');
    $this->approver = LoanFixtures::approver($this->branchId, 'Loan Index', 'Loan Collateral Perfect');
    $this->reader = Fixtures::staffInBranch($this->branchId, 'Loan Index');
});

/**
 * A new application for a new customer, taken to the stage asked for.
 */
function applicationAtStage($test, string $stage): string
{
    $customer = LoanFixtures::customer($test->branchId);
    LoanFixtures::income($customer, '150000');

    $id = $test->actingAs($test->officer, 'api')->postJson('/api/v1/loans', [
        'product_slug' => 'sme-loan', 'borrower_type' => 'individual', 'customer_public_id' => $customer->public_id,
        'requested_amount' => '150000', 'tenure' => 24, 'purpose' => 'Working capital for a grocery shop',
    ])->assertCreated()->json('data.public_id');

    $step = fn ($who, string $path, array $body = []) => $test->actingAs($who, 'api')->postJson("/api/v1/loans/{$id}/{$path}", $body)->assertOk();

    if ($stage === 'draft') {
        return $id;
    }

    $step($test->officer, 'submit');
    if ($stage === 'submitted') {
        return $id;
    }

    $step($test->reviewer, 'start-review');
    if ($stage === 'in_review') {
        return $id;
    }

    if ($stage === 'returned') {
        $step($test->reviewer, 'return', ['remarks' => 'Please correct the purpose']);

        return $id;
    }

    $step($test->verifier, 'verify');
    if ($stage === 'verified') {
        return $id;
    }

    $step($test->approver, 'approve');
    if ($stage === 'approved') {
        return $id;
    }

    $partyId = LoanParty::whereHas('loan', fn ($q) => $q->where('public_id', $id))->where('role', 'borrower')->value('id');
    $step($test->officer, "parties/{$partyId}/offer/accept", ['remarks' => 'Signed at the branch']);

    return $id;
}

const EDIT_STAGES = ['draft', 'submitted', 'in_review', 'returned', 'verified', 'approved', 'accepted'];

describe('editing', function () {
    it('is possible at every stage before payout for whoever holds Loan Update, and not for anyone else', function (string $stage) {
        $id = applicationAtStage($this, $stage);
        $body = ['purpose' => 'Stock for the shop', 'remarks' => 'Customer corrected the purpose'];

        $this->actingAs($this->reader, 'api')->putJson("/api/v1/loans/{$id}", $body)->assertForbidden();
        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$id}", $body)->assertOk()
            ->assertJsonPath('data.purpose', 'Stock for the shop')->assertJsonPath('data.status', $stage);
    })->with(EDIT_STAGES);

    it('asks for a reason once the application was submitted, but not before', function () {
        $draft = applicationAtStage($this, 'draft');
        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$draft}", ['purpose' => 'No reason needed on a draft'])->assertOk();

        $approved = applicationAtStage($this, 'approved');
        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$approved}", ['purpose' => 'No reason given'])->assertStatus(422)->assertJsonPath('code', 'EDIT_REASON_REQUIRED');
    });

    it('leaves the stage alone when only the purpose changes, and records who changed what and why', function () {
        $id = applicationAtStage($this, 'approved');

        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$id}", ['purpose' => 'Stock for the shop', 'remarks' => 'Typing mistake in the purpose'])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        expect(LoanActivity::where('remarks', 'like', 'Application changed while approved: Typing mistake%')->count())->toBe(1);
    });

    it('sends the application back for review when the money terms change, and voids the decisions given on the old figures', function (string $stage) {
        $id = applicationAtStage($this, $stage);

        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$id}", ['tenure' => 12, 'remarks' => 'Customer asked for a shorter term'])
            ->assertOk()->assertJsonPath('data.status', 'returned')->assertJsonPath('data.terms.tenure', 12);

        $loan = Loan::where('public_id', $id)->first();
        expect($loan->approved_amount)->toBeNull()->and($loan->approved_at)->toBeNull()->and($loan->accepted_at)->toBeNull()
            ->and(LoanParty::where('loan_id', $loan->id)->whereNotNull('consented_at')->count())->toBe(0);

        // It goes through the steps again, from the officer.
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
    })->with(['submitted', 'in_review', 'verified', 'approved', 'accepted']);

    it('does not send it back when the same figures are sent again', function () {
        $id = applicationAtStage($this, 'approved');

        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$id}", ['tenure' => 24, 'requested_amount' => '150000', 'remarks' => 'Saved the form again'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
    });

    it('changes only the details of a loan that was paid out; the money terms go through a restructure', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);

        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$loan->public_id}", ['purpose' => 'Stock for the shop'])->assertStatus(422)->assertJsonPath('code', 'EDIT_REASON_REQUIRED');
        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$loan->public_id}", ['purpose' => 'Stock for the shop', 'remarks' => 'The customer corrected the purpose'])
            ->assertOk()->assertJsonPath('data.purpose', 'Stock for the shop')->assertJsonPath('data.status', 'active');

        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$loan->public_id}", ['tenure' => 6, 'remarks' => 'Trying to shorten the term'])
            ->assertStatus(422)->assertJsonPath('code', 'MONEY_TERMS_AFTER_PAYOUT');
        expect($loan->fresh()->tenure)->toBe(12);
    });

    it('lets a reviewer send a decided application back directly, which voids the decisions', function () {
        $id = applicationAtStage($this, 'approved');

        $this->actingAs($this->reviewer, 'api')->postJson("/api/v1/loans/{$id}/return", ['remarks' => 'Income proof is out of date'])->assertOk()->assertJsonPath('data.status', 'returned');
        expect(Loan::where('public_id', $id)->value('approved_amount'))->toBeNull();
    });
});

describe('deleting', function () {
    it('is possible at every stage before payout for whoever holds Loan Delete, and the application comes back with Loan Restore', function (string $stage) {
        $id = applicationAtStage($this, $stage);

        $this->actingAs($this->reader, 'api')->deleteJson("/api/v1/loans/{$id}", ['remarks' => 'Entered by mistake'])->assertForbidden();
        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$id}", ['remarks' => 'Entered by mistake'])->assertOk();
        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}")->assertNotFound();

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/restore")->assertOk()->assertJsonPath('data.status', $stage);

        // The borrower is held to the one-open-loan rule again from the stage that holds the key.
        $loan = Loan::where('public_id', $id)->first();
        $keyed = LoanParty::where('loan_id', $loan->id)->whereNotNull('open_loan_key')->exists();
        expect($keyed)->toBe($stage !== 'draft');
    })->with(EDIT_STAGES);

    it('refuses while the security is legally in place, until the application is cancelled', function () {
        $id = applicationAtStage($this, 'draft');
        $collateral = $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/collaterals", [
            'type' => 'property_mortgage', 'description' => 'House in Maharagama', 'details' => ['deed_no' => 'D 1234/56'],
            'market_value' => '8000000', 'valued_on' => today()->toDateString(),
        ])->assertCreated()->json('data.public_id');

        // Stand in for an application that was approved and whose mortgage is registered.
        Loan::where('public_id', $id)->first()->forceFill(['status' => 'approved'])->save();
        Collateral::where('public_id', $collateral)->update(['status' => 'perfected']);

        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$id}", ['remarks' => 'Customer changed his mind'])->assertStatus(409)->assertJsonPath('code', 'COLLATERAL_PERFECTED');

        // Cancelling releases the security; then it can be deleted.
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/cancel", ['remarks' => 'Customer changed his mind'])->assertOk();
        expect(Collateral::where('public_id', $collateral)->first()->status->value)->toBe('released');
        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$id}", ['remarks' => 'Clearing the cancelled application'])->assertOk();
    });

    it('needs its own permission for a loan that was paid out', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);

        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$loan->public_id}", ['remarks' => 'Trying to delete'])
            ->assertStatus(403)->assertJsonPath('code', 'DELETE_AFTER_PAYOUT_NOT_ALLOWED');
    });
});

/**
 * The net of everything the loan ever posted, per GL account (debit minus credit).
 */
function loanGlNet(Loan $loan): array
{
    return App\Models\GlJournalLine::query()
        ->join('gl_journals', 'gl_journals.id', '=', 'gl_journal_lines.gl_journal_id')
        ->where('gl_journals.loan_id', $loan->id)->where('gl_journals.status', 'posted')
        ->groupBy('gl_journal_lines.gl_account_id')
        ->selectRaw('gl_journal_lines.gl_account_id as account, SUM(gl_journal_lines.debit) - SUM(gl_journal_lines.credit) as net')
        ->pluck('net', 'account')->map(fn ($n) => round((float) $n, 2))->all();
}

function booksAreConsistent(): bool
{
    $recon = app(App\Services\Accounting\LedgerReconciliationService::class)->run();
    $trial = app(App\Services\Accounting\GlReportService::class)->trialBalance(Carbon\CarbonImmutable::today(), null);

    return $recon['reconciled'] && $trial['balanced'];
}

describe('after payout', function () {
    beforeEach(function () {
        $this->cashier = Fixtures::staffInBranch($this->branchId, 'Payment Create', 'Payment Index', 'Payment Reversal Request', 'Loan Index');
        $this->boss = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Delete', 'Loan Restore', 'Loan Delete After Payout');
    });

    function payOnLoan($test, $loan, string $amount)
    {
        return $test->actingAs($test->cashier, 'api')->withHeader('Idempotency-Key', 'pay-'.str()->random(12))
            ->postJson('/api/v1/payments', ['loan_public_id' => $loan->public_id, 'amount' => $amount, 'channel' => 'cash'])->assertCreated();
    }

    it('reverses every accounting entry of a loan that was paid out, keeps the books consistent, and puts them back on restore', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: Carbon\CarbonImmutable::today()->subDays(35));
        $this->artisan('eod:run')->assertSuccessful();
        payOnLoan($this, $loan, '10000');

        $before = loanGlNet($loan);
        expect($before)->not->toBeEmpty()->and(booksAreConsistent())->toBeTrue();

        $this->actingAs($this->boss, 'api')->deleteJson("/api/v1/loans/{$loan->public_id}", ['remarks' => 'Entered on the wrong customer'])->assertOk();

        // The loan is gone from the screens; everything it posted nets to zero in every account; the books still agree.
        $this->actingAs($this->boss, 'api')->getJson("/api/v1/loans/{$loan->public_id}")->assertNotFound();
        expect(Loan::withTrashed()->find($loan->id)->trashed())->toBeTrue()
            ->and(array_filter(loanGlNet($loan), fn ($n) => $n !== 0.0))->toBe([])
            ->and(booksAreConsistent())->toBeTrue()
            ->and(LoanParty::withTrashed()->where('loan_id', $loan->id)->whereNotNull('open_loan_key')->count())->toBe(0);
        $this->actingAs($this->cashier, 'api')->getJson('/api/v1/payments')->assertOk()->assertJsonPath('data.meta.total', 0);

        // The nightly run leaves it alone: no new entries.
        $journals = App\Models\GlJournal::where('loan_id', $loan->id)->count();
        $this->artisan('eod:run')->assertSuccessful();
        expect(App\Models\GlJournal::where('loan_id', $loan->id)->count())->toBe($journals);

        // Restore: the original entries are back, the loan is open again for its borrower.
        $this->actingAs($this->boss, 'api')->postJson("/api/v1/loans/{$loan->public_id}/restore")->assertOk()->assertJsonPath('data.status', 'active');
        expect(loanGlNet($loan))->toBe($before)->and(booksAreConsistent())->toBeTrue()
            ->and(LoanParty::where('loan_id', $loan->id)->whereNotNull('open_loan_key')->count())->toBeGreaterThan(0);
    });

    it('does the same for a loan that was settled', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: Carbon\CarbonImmutable::today()->subDays(10));
        $settler = Fixtures::staffInBranch($this->branchId, 'Loan Settle', 'Loan Index');
        $quote = $this->actingAs($settler, 'api')->getJson("/api/v1/loans/{$loan->public_id}/settlement-quote")->json('data');
        $this->actingAs($settler, 'api')->withHeader('Idempotency-Key', 'settle-void-0001')
            ->postJson("/api/v1/loans/{$loan->public_id}/settle", ['amount' => $quote['total'], 'channel' => 'cash'])->assertCreated();
        expect(booksAreConsistent())->toBeTrue();

        $this->actingAs($this->boss, 'api')->deleteJson("/api/v1/loans/{$loan->public_id}", ['remarks' => 'Closed on the wrong loan'])->assertOk();

        expect(array_filter(loanGlNet($loan), fn ($n) => $n !== 0.0))->toBe([])->and(booksAreConsistent())->toBeTrue();
    });

    it('waits for the requests in the approval inbox to be decided', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);
        $txn = payOnLoan($this, $loan, '5000')->json('data.public_id');
        $this->actingAs($this->cashier, 'api')->postJson("/api/v1/payments/{$txn}/reverse", ['remarks' => 'Transfer recalled'])->assertStatus(202);

        $this->actingAs($this->boss, 'api')->deleteJson("/api/v1/loans/{$loan->public_id}", ['remarks' => 'Entered by mistake'])
            ->assertStatus(409)->assertJsonPath('code', 'PENDING_APPROVALS');
        expect(Loan::find($loan->id))->not->toBeNull();
    });
});

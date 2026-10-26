<?php

declare(strict_types=1);

use App\Models\Collateral;
use App\Models\GlAccount;
use App\Models\GlJournal;
use App\Models\Loan;
use App\Models\LoanActivity;
use App\Models\LoanParty;
use App\Models\LoanTransaction;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

/**
 * Every record a loan owns can be created, read, changed and removed, each action by its own permission,
 * and the safe rules still hold: a paid-out loan is never deleted, a verified collateral never edited,
 * the ledger is corrected by reversal.
 */
beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    Fixtures::fakeSms();
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);

    $this->officer = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Create', 'Loan Update', 'Loan Delete', 'Loan Submit', 'Loan Cancel',
        'Loan Note Index', 'Loan Note Create', 'Loan Note Update', 'Loan Note Delete',
        'Loan Guarantor Index', 'Loan Guarantor Create', 'Loan Guarantor Update', 'Loan Guarantor Delete',
        'Loan Collateral Index', 'Loan Collateral Create', 'Loan Collateral Update', 'Loan Collateral Delete');
    $this->colleague = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Note Index', 'Loan Note Update', 'Loan Note Delete');
    $this->bystander = Fixtures::staffInBranch($this->branchId, 'Loan Index');
    $this->restorer = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Restore');

    $this->borrower = LoanFixtures::customer($this->branchId);
    LoanFixtures::income($this->borrower, '150000');
});

function crudApply($test, array $overrides = [])
{
    return $test->actingAs($test->officer, 'api')->postJson('/api/v1/loans', array_merge([
        'product_slug' => 'sme-loan',
        'borrower_type' => 'individual',
        'customer_public_id' => $test->borrower->public_id,
        'requested_amount' => '150000',
        'tenure' => 24,
        'purpose' => 'Working capital for a grocery shop',
    ], $overrides));
}

describe('deleting a loan application', function () {
    it('needs its own permission and a reason', function () {
        $id = crudApply($this)->json('data.public_id');

        $this->actingAs($this->bystander, 'api')->deleteJson("/api/v1/loans/{$id}", ['remarks' => 'Entered twice'])->assertForbidden();
        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$id}")->assertStatus(422);
        expect(Loan::where('public_id', $id)->exists())->toBeTrue();
    });

    it('removes a draft, keeps the row, and frees the customer and the guarantor', function () {
        $guarantor = LoanFixtures::customer($this->branchId);
        $id = crudApply($this)->json('data.public_id');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/guarantors", ['customer_public_id' => $guarantor->public_id])->assertCreated();

        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$id}", ['remarks' => 'Entered twice'])->assertOk();

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}")->assertNotFound();
        expect(Loan::withTrashed()->where('public_id', $id)->first()->trashed())->toBeTrue()
            ->and(LoanParty::where('customer_id', $guarantor->id)->exists())->toBeFalse();

        // The same customer can apply again.
        crudApply($this)->assertCreated();
    });

    it('removes an application that is already in the pipeline, and a cancelled one', function () {
        $id = crudApply($this)->json('data.public_id');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/submit")->assertOk();

        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$id}", ['remarks' => 'Entered twice'])->assertOk();
        expect(Loan::withTrashed()->where('public_id', $id)->first()->status->value)->toBe('submitted');

        $second = crudApply($this)->json('data.public_id');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$second}/cancel", ['remarks' => 'Customer withdrew'])->assertOk();
        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$second}", ['remarks' => 'Cleaning up'])->assertOk();
    });

    it('does not delete a loan that was paid out for someone without the permission for that', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);

        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$loan->public_id}", ['remarks' => 'Mistake'])
            ->assertStatus(403)->assertJsonPath('code', 'DELETE_AFTER_PAYOUT_NOT_ALLOWED');
        expect($loan->fresh()->trashed())->toBeFalse();
    });
});

describe('loan notes', function () {
    it('can be listed, corrected and removed by their author only, with the old text audited', function () {
        $id = crudApply($this)->json('data.public_id');
        $noteId = $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/notes", ['remarks' => 'Called the customer'])->assertCreated()->json('data.id');

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}/notes")->assertOk()->assertJsonCount(1, 'data.items');

        // Someone else with the permission still cannot change another person's note.
        $this->actingAs($this->colleague, 'api')->putJson("/api/v1/loans/{$id}/notes/{$noteId}", ['remarks' => 'Tampered'])->assertNotFound();
        $this->actingAs($this->colleague, 'api')->deleteJson("/api/v1/loans/{$id}/notes/{$noteId}")->assertNotFound();
        $this->actingAs($this->bystander, 'api')->putJson("/api/v1/loans/{$id}/notes/{$noteId}", ['remarks' => 'Tampered'])->assertForbidden();

        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$id}/notes/{$noteId}", ['remarks' => 'Called the customer twice'])
            ->assertOk()->assertJsonPath('data.remarks', 'Called the customer twice');

        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$id}/notes/{$noteId}")->assertOk();
        expect(LoanActivity::find($noteId))->toBeNull();
    });

    it('keeps every other timeline entry untouchable', function () {
        $id = crudApply($this)->json('data.public_id');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/submit")->assertOk();
        $status = LoanActivity::where('type', 'status_change')->first();

        expect(fn () => $status->update(['remarks' => 'x']))->toThrow(LogicException::class)
            ->and(fn () => $status->delete())->toThrow(LogicException::class);
        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$id}/notes/{$status->id}")->assertNotFound();
    });
});

describe('guarantors', function () {
    it('can be listed, corrected and removed, each by its own permission', function () {
        $guarantor = LoanFixtures::customer($this->branchId);
        $id = crudApply($this)->json('data.public_id');
        $partyId = $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/guarantors", ['customer_public_id' => $guarantor->public_id, 'relationship' => 'friend'])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}/guarantors")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->bystander, 'api')->getJson("/api/v1/loans/{$id}/guarantors")->assertForbidden();

        $this->actingAs($this->bystander, 'api')->putJson("/api/v1/loans/{$id}/guarantors/{$partyId}", ['relationship' => 'family'])->assertForbidden();
        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$id}/guarantors/{$partyId}", ['relationship' => 'family'])
            ->assertOk()->assertJsonPath('data.relationship', 'family');

        $this->actingAs($this->bystander, 'api')->deleteJson("/api/v1/loans/{$id}/guarantors/{$partyId}")->assertForbidden();
        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$id}/guarantors/{$partyId}")->assertOk();
        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}/guarantors")->assertJsonCount(0, 'data');
    });
});

describe('collateral', function () {
    function crudGold($test, string $id, string $description = 'Two 22K bangles')
    {
        return $test->actingAs($test->officer, 'api')->postJson("/api/v1/loans/{$id}/collaterals", [
            'type' => 'property_mortgage', 'description' => $description, 'details' => ['deed_no' => 'D 1234/56'],
            'market_value' => '8000000', 'valued_on' => today()->toDateString(),
        ]);
    }

    it('can be corrected while proposed, and is locked once verified', function () {
        $id = crudApply($this)->json('data.public_id');
        $collateralId = crudGold($this, $id)->assertCreated()->json('data.public_id');

        $this->actingAs($this->bystander, 'api')->putJson("/api/v1/loans/{$id}/collaterals/{$collateralId}", [])->assertForbidden();

        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$id}/collaterals/{$collateralId}", [
            'type' => 'property_mortgage', 'description' => 'House in Nugegoda', 'details' => ['deed_no' => 'D 9999/01'],
            'market_value' => '9000000', 'valued_on' => today()->toDateString(),
        ])->assertOk()->assertJsonPath('data.description', 'House in Nugegoda')->assertJsonPath('data.market_value', '9000000.00');

        Collateral::where('public_id', $collateralId)->first()->forceFill(['status' => 'verified'])->save();

        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$id}/collaterals/{$collateralId}", [
            'type' => 'property_mortgage', 'description' => 'Changed again', 'details' => ['deed_no' => 'D 9999/01'],
            'market_value' => '9000000', 'valued_on' => today()->toDateString(),
        ])->assertStatus(409)->assertJsonPath('code', 'COLLATERAL_NOT_EDITABLE');
    });
});

describe('payments and journals', function () {
    beforeEach(function () {
        $this->cashier = Fixtures::staffInBranch($this->branchId, 'Payment Index', 'Payment Create', 'Payment Update', 'Payment Delete', 'Loan Index');
        $this->supervisor = Fixtures::staffInBranch($this->branchId, 'Approval Request Index', 'Payment Reversal Approve', 'GL Journal Approve');
        $this->accountant = staffWith('Branch Data All', 'GL Journal Index', 'GL Journal Request', 'GL Journal Update', 'GL Journal Delete');
    });

    it('lets a payment text be corrected but never its amount, and turns delete into a reversal request', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);
        $txn = $this->actingAs($this->cashier, 'api')->withHeader('Idempotency-Key', 'pay-crud-0001')
            ->postJson('/api/v1/payments', ['loan_public_id' => $loan->public_id, 'amount' => '5000', 'channel' => 'cash'])->assertCreated()->json('data.public_id');

        $this->actingAs($this->cashier, 'api')->putJson("/api/v1/payments/{$txn}", ['narration' => 'Paid at the counter'])->assertOk()->assertJsonPath('data.narration', 'Paid at the counter');
        $this->actingAs($this->cashier, 'api')->putJson("/api/v1/payments/{$txn}", ['amount' => '1'])->assertOk();
        expect(LoanTransaction::where('public_id', $txn)->value('amount'))->toBe('5000.00');

        $this->actingAs($this->bystander, 'api')->putJson("/api/v1/payments/{$txn}", ['narration' => 'x'])->assertForbidden();
        $this->actingAs($this->bystander, 'api')->deleteJson("/api/v1/payments/{$txn}", ['remarks' => 'Wrong loan'])->assertForbidden();

        $approval = $this->actingAs($this->cashier, 'api')->deleteJson("/api/v1/payments/{$txn}", ['remarks' => 'Wrong loan'])->assertStatus(202)->json('data.public_id');
        $this->actingAs($this->supervisor, 'api')->postJson("/api/v1/approval-requests/{$approval}/approve", ['remarks' => 'Checked'])->assertOk();

        expect(LoanTransaction::where('reverses_transaction_id', LoanTransaction::where('public_id', $txn)->value('id'))->exists())->toBeTrue();
    });

    it('replaces or withdraws a pending journal, and reverses a posted manual one', function () {
        $cash = GlAccount::where('slug', 'cash-in-hand')->value('id');
        $bank = GlAccount::where('slug', 'bank')->value('id');
        $body = fn (string $amount, string $narration) => ['value_date' => today()->toDateString(), 'narration' => $narration, 'lines' => [
            ['gl_account_id' => $cash, 'debit' => $amount],
            ['gl_account_id' => $bank, 'credit' => $amount],
        ]];

        $this->actingAs($this->accountant, 'api')->postJson('/api/v1/gl/journals', $body('100', 'First attempt'))->assertStatus(202);
        $first = GlJournal::where('narration', 'First attempt')->first();

        // Replace: the old request is withdrawn and the corrected journal waits for approval.
        $this->actingAs($this->accountant, 'api')->putJson("/api/v1/gl/journals/{$first->public_id}", $body('250', 'Corrected'))->assertStatus(202);
        expect($first->fresh()->status->value)->toBe('rejected')
            ->and(GlJournal::where('narration', 'Corrected')->first()->status->value)->toBe('pending');

        // Someone else cannot touch it.
        $other = staffWith('Branch Data All', 'GL Journal Update', 'GL Journal Delete');
        $second = GlJournal::where('narration', 'Corrected')->first();
        $this->actingAs($other, 'api')->deleteJson("/api/v1/gl/journals/{$second->public_id}", ['remarks' => 'Not mine'])->assertStatus(403);

        // Withdraw it.
        $this->actingAs($this->accountant, 'api')->deleteJson("/api/v1/gl/journals/{$second->public_id}", ['remarks' => 'Not needed'])->assertOk();
        expect($second->fresh()->status->value)->toBe('rejected');

        // Post one, then delete = a reversing journal for approval.
        $approval = $this->actingAs($this->accountant, 'api')->postJson('/api/v1/gl/journals', $body('400', 'To be reversed'))->assertStatus(202)->json('data.public_id');
        $this->actingAs($this->supervisor, 'api')->postJson("/api/v1/approval-requests/{$approval}/approve", ['remarks' => 'OK'])->assertOk();
        $posted = GlJournal::withoutGlobalScopes()->where('narration', 'To be reversed')->first();

        $reversal = $this->actingAs($this->accountant, 'api')->deleteJson("/api/v1/gl/journals/{$posted->public_id}", ['remarks' => 'Posted in error'])->assertStatus(202)->json('data.public_id');
        $this->actingAs($this->accountant, 'api')->deleteJson("/api/v1/gl/journals/{$posted->public_id}", ['remarks' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'JOURNAL_ALREADY_REVERSED');
        $this->actingAs($this->supervisor, 'api')->postJson("/api/v1/approval-requests/{$reversal}/approve", ['remarks' => 'OK'])->assertOk();

        $net = fn (int $account) => (float) App\Models\GlJournalLine::whereHas('journal', fn ($q) => $q->withoutGlobalScopes()->where('status', 'posted'))->where('gl_account_id', $account)->selectRaw('SUM(debit) - SUM(credit) AS n')->value('n');
        expect($net($cash))->toBe(0.0)->and($net($bank))->toBe(0.0);
    });
});


describe('restoring a deleted loan', function () {
    function crudPledge($test, string $id)
    {
        return $test->actingAs($test->officer, 'api')->postJson("/api/v1/loans/{$id}/collaterals", [
            'type' => 'property_mortgage', 'description' => 'House in Maharagama', 'details' => ['deed_no' => 'D 1234/56'],
            'market_value' => '8000000', 'valued_on' => today()->toDateString(),
        ])->assertCreated()->json('data.public_id');
    }

    it('brings back the loan with its guarantor and collateral, found through the deleted list', function () {
        $guarantor = LoanFixtures::customer($this->branchId);
        $id = crudApply($this)->json('data.public_id');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/guarantors", ['customer_public_id' => $guarantor->public_id, 'relationship' => 'friend'])->assertCreated();
        $collateralId = crudPledge($this, $id);

        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$id}", ['remarks' => 'Entered twice'])->assertOk();

        // Gone from the normal list; only someone who may restore can list the deleted ones.
        $this->actingAs($this->restorer, 'api')->getJson('/api/v1/loans')->assertJsonMissing(['public_id' => $id]);
        $this->actingAs($this->bystander, 'api')->getJson('/api/v1/loans?only_deleted=1')->assertForbidden();
        $this->actingAs($this->restorer, 'api')->getJson('/api/v1/loans?only_deleted=1')->assertOk()->assertJsonPath('data.items.0.public_id', $id);

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/restore")->assertForbidden();
        $this->actingAs($this->restorer, 'api')->postJson("/api/v1/loans/{$id}/restore")->assertOk()->assertJsonPath('data.public_id', $id);

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}/guarantors")->assertJsonCount(1, 'data');
        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}/collaterals")->assertJsonPath('data.0.public_id', $collateralId);
        expect(LoanActivity::where('remarks', 'Application restored')->count())->toBe(1);

        $this->actingAs($this->restorer, 'api')->postJson("/api/v1/loans/{$id}/restore")->assertNotFound();
    });

    it('keeps the customer free while it is deleted, and refuses the restore if they opened another loan', function () {
        $id = crudApply($this)->json('data.public_id');
        // A returned application holds its borrower's open-loan key (set by hand here; the workflow sets it on submit).
        Loan::where('public_id', $id)->first()->forceFill(['status' => 'returned'])->save();
        LoanParty::whereHas('loan', fn ($q) => $q->where('public_id', $id))->update(['open_loan_key' => $this->borrower->id]);

        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$id}", ['remarks' => 'Entered twice'])->assertOk();
        expect(LoanParty::withTrashed()->where('customer_id', $this->borrower->id)->value('open_loan_key'))->toBeNull();

        // The customer applies again and that application becomes open.
        $second = crudApply($this)->assertCreated()->json('data.public_id');
        LoanParty::whereHas('loan', fn ($q) => $q->where('public_id', $second))->update(['open_loan_key' => $this->borrower->id]);

        $this->actingAs($this->restorer, 'api')->postJson("/api/v1/loans/{$id}/restore")->assertStatus(409)->assertJsonPath('code', 'BORROWER_HAS_OPEN_LOAN');
        expect(Loan::withTrashed()->where('public_id', $id)->first()->trashed())->toBeTrue()
            ->and(LoanParty::onlyTrashed()->count())->toBe(1);
    });

    it('still erases a guarantor or collateral that is removed by hand', function () {
        $guarantor = LoanFixtures::customer($this->branchId);
        $id = crudApply($this)->json('data.public_id');
        $partyId = $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/guarantors", ['customer_public_id' => $guarantor->public_id])->json('data.id');
        $collateralId = crudPledge($this, $id);

        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$id}/guarantors/{$partyId}")->assertOk();
        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$id}/collaterals/{$collateralId}")->assertOk();

        // Removed by hand means gone for good, so the same guarantor can be added again.
        expect(LoanParty::withTrashed()->where('customer_id', $guarantor->id)->exists())->toBeFalse()
            ->and(Collateral::withTrashed()->where('public_id', $collateralId)->exists())->toBeFalse();
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/guarantors", ['customer_public_id' => $guarantor->public_id])->assertCreated();
    });
});

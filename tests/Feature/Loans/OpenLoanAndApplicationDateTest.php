<?php

declare(strict_types=1);

use App\Models\Loan;
use App\Models\LoanParty;
use App\Models\Setting;
use App\Support\BusinessDate;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

/**
 * Two rules of the application step:
 *  - the date the customer applied is chosen by the officer (a customer who comes back to finish an application);
 *  - a customer with an open loan or an application in progress is told at the first step, not at the end, and a
 *    second loan is possible only as a special case by someone holding "Loan Multiple Open Allow".
 */
beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    Fixtures::fakeSms();
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);

    $own = ['Loan Index', 'Loan Create', 'Loan Update', 'Loan Delete', 'Loan Restore', 'Loan Submit', 'Loan Cancel', 'Customer Index', 'Loan Guarantor Create'];
    $this->officer = Fixtures::staffInBranch($this->branchId, ...$own);
    $this->boss = Fixtures::staffInBranch($this->branchId, ...$own, ...['Loan Multiple Open Allow']);

    $this->borrower = LoanFixtures::customer($this->branchId);
    LoanFixtures::income($this->borrower, '150000');
    $this->today = BusinessDate::todayImmutable();
});

function openApply($test, $who, $customer = null, array $extra = [])
{
    return $test->actingAs($who, 'api')->postJson('/api/v1/loans', array_merge([
        'product_slug' => 'sme-loan', 'borrower_type' => 'individual', 'customer_public_id' => ($customer ?? $test->borrower)->public_id,
        'requested_amount' => '150000', 'tenure' => 24, 'purpose' => 'Working capital for a grocery shop',
    ], $extra));
}

describe('the application date', function () {
    it('is today unless the officer picks a day, and is shown on the loan and in the list', function () {
        $id = openApply($this, $this->officer)->assertCreated()->assertJsonPath('data.dates.application_date', $this->today->toDateString())->json('data.public_id');

        $other = LoanFixtures::customer($this->branchId);
        $day = $this->today->subDays(6)->toDateString();
        $picked = openApply($this, $this->officer, $other, ['application_date' => $day])->assertCreated()->assertJsonPath('data.application_date', $day)->json('data.public_id');

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$picked}")->assertJsonPath('data.dates.application_date', $day);
        $rows = collect($this->actingAs($this->officer, 'api')->getJson('/api/v1/loans')->json('data.items'))->pluck('application_date', 'public_id');
        expect($rows[$id])->toBe($this->today->toDateString())->and($rows[$picked])->toBe($day);
        // created_at stays the real moment the record was saved.
        expect(Loan::where('public_id', $picked)->first()->created_at->isSameDay(now()))->toBeTrue();
    });

    it('can never be in the future, nor further back than the setting allows', function () {
        openApply($this, $this->officer, null, ['application_date' => $this->today->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonFragment(['messages' => ['The application date cannot be in the future.']]);

        // 30 days by default.
        openApply($this, $this->officer, null, ['application_date' => $this->today->subDays(31)->toDateString()])->assertStatus(422);
        openApply($this, $this->officer, null, ['application_date' => $this->today->subDays(30)->toDateString()])->assertCreated();

        // The setting row comes with the migration (30); an administrator lowers it.
        Setting::where('key', 'loan.application_backdate_days')->first()->update(['value' => '2']);
        openApply($this, $this->officer, LoanFixtures::customer($this->branchId), ['application_date' => $this->today->subDays(3)->toDateString()])->assertStatus(422);
        openApply($this, $this->officer, LoanFixtures::customer($this->branchId), ['application_date' => $this->today->subDays(2)->toDateString()])->assertCreated();
    });

    it('can be set again while the application is a draft, and not after it is submitted', function () {
        $id = openApply($this, $this->officer)->json('data.public_id');
        $day = $this->today->subDays(4)->toDateString();

        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$id}", ['application_date' => $day])->assertOk()->assertJsonPath('data.dates.application_date', $day);
        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$id}", ['application_date' => $this->today->addDays(2)->toDateString()])->assertStatus(422);

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/submit")->assertOk();
        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$id}", ['application_date' => $this->today->toDateString()])->assertStatus(409)->assertJsonPath('code', 'LOAN_NOT_EDITABLE');
        expect(Loan::where('public_id', $id)->first()->application_date->toDateString())->toBe($day);
    });

    it('is the day applications are counted on the dashboard', function () {
        $viewer = Fixtures::staffInBranch($this->branchId, 'Dashboard View');
        $day = $this->today->subDays(5)->toDateString();
        openApply($this, $this->officer, null, ['application_date' => $day])->assertCreated();

        $received = fn (string $from, string $to) => (int) $this->actingAs($viewer, 'api')->getJson("/api/v1/dashboard/overview?from={$from}&to={$to}")->assertOk()->json('data.kpis.applications_received.value');

        expect($received($day, $day))->toBe(1)->and($received($this->today->toDateString(), $this->today->toDateString()))->toBe(0);
    });
});

describe('one open loan per customer, from the first step', function () {
    it('stops a second application at once while the first is only a draft, and says which one to continue', function () {
        $first = openApply($this, $this->officer)->assertCreated()->json('data');

        $r = openApply($this, $this->officer)->assertStatus(409)->assertJsonPath('code', 'BORROWER_HAS_OPEN_LOAN');
        expect($r->json('details.problems.0.loan.public_id'))->toBe($first['public_id'])
            ->and($r->json('details.problems.0.loan.application_no'))->toBe($first['application_no'])
            ->and($r->json('details.problems.0.loan.status'))->toBe('draft');
        expect(Loan::count())->toBe(1);
    });

    it('stops a customer with an active loan at the first step', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);

        openApply($this, $this->officer, $loan->customer)->assertStatus(409)->assertJsonPath('code', 'BORROWER_HAS_OPEN_LOAN')
            ->assertJsonPath('details.problems.0.loan.status', 'active');
    });

    it('shows an active loan on the customer record, so the screen can stop the officer when the customer is picked', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/customers/{$loan->customer->public_id}")->assertOk()
            ->assertJsonPath('data.open_loan.public_id', $loan->public_id)->assertJsonPath('data.open_loan.status', 'active')
            ->assertJsonPath('data.open_loan.loan_no', $loan->loan_no);
    });

    it('also stops a co-borrower who has an application in progress', function () {
        $busy = LoanFixtures::customer($this->branchId);
        openApply($this, $this->officer, $busy)->assertCreated();

        openApply($this, $this->officer, null, ['borrower_type' => 'joint', 'co_borrower_public_ids' => [$busy->public_id]])->assertStatus(409)->assertJsonPath('code', 'BORROWER_HAS_OPEN_LOAN');
    });

    it('tells the officer on the customer record, the lookup and the eligibility check before anything is typed', function () {
        $this->actingAs($this->officer, 'api')->getJson("/api/v1/customers/{$this->borrower->public_id}")->assertOk()->assertJsonPath('data.open_loan', null);

        $loan = openApply($this, $this->officer)->json('data');

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/customers/{$this->borrower->public_id}")
            ->assertJsonPath('data.open_loan.public_id', $loan['public_id'])->assertJsonPath('data.open_loan.status', 'draft');

        $eligibility = $this->actingAs($this->officer, 'api')->getJson("/api/v1/customers/{$this->borrower->public_id}/eligibility?product_slug=sme-loan")->assertOk();
        expect($eligibility->json('data.eligible'))->toBeFalse()
            ->and(collect($eligibility->json('data.problems'))->firstWhere('code', 'BORROWER_HAS_OPEN_LOAN')['loan']['application_no'])->toBe($loan['application_no']);
    });

    it('lets the customer start again once the draft is deleted or the application cancelled', function () {
        $id = openApply($this, $this->officer)->json('data.public_id');
        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$id}", ['remarks' => 'Customer came back to start again'])->assertOk();

        $second = openApply($this, $this->officer)->assertCreated()->json('data.public_id');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$second}/submit")->assertOk();
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$second}/cancel", ['remarks' => 'Customer withdrew'])->assertOk();

        openApply($this, $this->officer)->assertCreated();
    });

    it('follows the setting: with "submitted" a draft does not block, a submitted application does', function () {
        Setting::create(['key' => 'loan.open_loan_scope', 'group' => 'loans', 'type' => 'string', 'value' => 'submitted', 'rules' => ['value' => 'required'], 'label' => 'x']);

        $first = openApply($this, $this->officer)->assertCreated()->json('data.public_id');
        $second = openApply($this, $this->officer)->assertCreated()->json('data.public_id');

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$first}/submit")->assertOk();
        openApply($this, $this->officer)->assertStatus(409)->assertJsonPath('code', 'BORROWER_HAS_OPEN_LOAN');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$second}/submit")->assertStatus(409)->assertJsonPath('code', 'BORROWER_HAS_OPEN_LOAN');
    });

    it('does not bring a deleted draft back when the customer has started another one since', function () {
        $old = openApply($this, $this->officer)->json('data.public_id');
        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/loans/{$old}", ['remarks' => 'Entered by mistake'])->assertOk();
        $new = openApply($this, $this->officer)->assertCreated()->json('data');

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$old}/restore")->assertStatus(409)
            ->assertJsonPath('code', 'BORROWER_HAS_OPEN_LOAN')->assertJsonPath('details.loan.public_id', $new['public_id']);
    });
});

describe('the special case: more than one open loan', function () {
    it('needs the permission and a written reason', function () {
        openApply($this, $this->officer)->assertCreated();
        $reason = ['allow_multiple_open' => true, 'multiple_open_reason' => 'Existing gold loan is being topped up with a business loan'];

        openApply($this, $this->officer, null, $reason)->assertStatus(403)->assertJsonPath('code', 'MULTIPLE_OPEN_NOT_ALLOWED');
        openApply($this, $this->boss, null, ['allow_multiple_open' => true])->assertStatus(422);
        openApply($this, $this->boss, null, ['allow_multiple_open' => true, 'multiple_open_reason' => 'short'])->assertStatus(422);
        expect(Loan::count())->toBe(1);
    });

    it('gives a customer a second loan that says who allowed it and why, and still lets it be submitted', function () {
        $first = openApply($this, $this->officer)->json('data.public_id');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$first}/submit")->assertOk();

        $reason = 'Existing gold loan is being topped up with a business loan';
        $second = openApply($this, $this->boss, null, ['allow_multiple_open' => true, 'multiple_open_reason' => $reason])->assertCreated()
            ->assertJsonPath('data.multiple_open', true)->assertJsonPath('data.special_open_loan.reason', $reason)
            ->assertJsonPath('data.special_open_loan.allowed_by.id', $this->boss->id)->json('data.public_id');

        // Submitting does not trip over the first loan, and the loan list marks it.
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$second}/submit")->assertOk();
        expect(collect($this->actingAs($this->officer, 'api')->getJson('/api/v1/loans')->json('data.items'))->pluck('multiple_open', 'public_id')->all())->toBe([$second => true, $first => false]);
        expect(LoanParty::whereHas('loan', fn ($q) => $q->where('public_id', $second))->value('open_loan_key'))->toBeNull();

        // A third ordinary application is still refused: the special case belongs to that one loan.
        openApply($this, $this->officer)->assertStatus(409)->assertJsonPath('code', 'BORROWER_HAS_OPEN_LOAN');
    });

    it('can be switched on for an application that is still a draft, and off again unless the customer has another loan', function () {
        openApply($this, $this->officer)->assertCreated();
        $other = LoanFixtures::customer($this->branchId);
        $id = openApply($this, $this->officer, $other)->json('data.public_id');
        LoanFixtures::income($other, '150000');

        // $other has only this draft; give them a second via the special case on that first draft's customer instead.
        $draftId = Loan::where('customer_id', $this->borrower->id)->value('public_id');

        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$draftId}", ['allow_multiple_open' => true, 'multiple_open_reason' => 'A very good reason indeed'])
            ->assertStatus(403)->assertJsonPath('code', 'MULTIPLE_OPEN_NOT_ALLOWED');

        $this->actingAs($this->boss, 'api')->putJson("/api/v1/loans/{$draftId}", ['allow_multiple_open' => true, 'multiple_open_reason' => 'A very good reason indeed'])
            ->assertOk()->assertJsonPath('data.multiple_open', true);

        // The borrower now also gets a second one, then the first special case cannot be withdrawn while the other loan is open.
        $second = openApply($this, $this->boss, null, ['allow_multiple_open' => true, 'multiple_open_reason' => 'A second very good reason'])->assertCreated()->json('data.public_id');
        $this->actingAs($this->boss, 'api')->putJson("/api/v1/loans/{$draftId}", ['allow_multiple_open' => false])->assertStatus(409)->assertJsonPath('code', 'BORROWER_HAS_OPEN_LOAN');
        expect(Loan::where('public_id', $draftId)->first()->multiple_open_at)->not->toBeNull();

        // Once the other loan is gone, withdrawing it works.
        $this->actingAs($this->boss, 'api')->deleteJson("/api/v1/loans/{$second}", ['remarks' => 'No longer needed'])->assertOk();
        $this->actingAs($this->boss, 'api')->putJson("/api/v1/loans/{$draftId}", ['allow_multiple_open' => false])->assertOk()->assertJsonPath('data.multiple_open', false);
        expect($id)->not->toBeEmpty();
    });
});

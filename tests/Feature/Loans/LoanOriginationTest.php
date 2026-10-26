<?php

declare(strict_types=1);

use App\Models\Collateral;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanParty;
use App\Models\LoanTransaction;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Spatie\Permission\Models\Permission;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    $this->sms = Fixtures::fakeSms();

    $this->product = LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);

    // A different person for every step, as maker-checker requires.
    $this->officer = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Create', 'Loan Update', 'Loan Submit', 'Loan Guarantor Index', 'Loan Guarantor Create', 'Loan Guarantor Update', 'Loan Guarantor Delete', 'Loan Collateral Index', 'Loan Collateral Create', 'Loan Collateral Update', 'Loan Collateral Delete', 'Loan Offer Manage', 'Loan Cancel');
    $this->reviewer = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Review', 'Loan Reject');
    $this->verifier = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Verify', 'Loan Collateral Verify');
    $this->approver = LoanFixtures::approver($this->branchId, 'Loan Index', 'Loan Collateral Perfect');
    $this->disburser = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Disburse');

    $this->borrower = LoanFixtures::customer($this->branchId);
    LoanFixtures::income($this->borrower, '150000');
});

function loanApply($test, array $overrides = [])
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

function loanToVerified($test, string $id): void
{
    $test->actingAs($test->officer, 'api')->postJson("/api/v1/loans/{$id}/submit")->assertOk();
    $test->actingAs($test->reviewer, 'api')->postJson("/api/v1/loans/{$id}/start-review")->assertOk();
    $test->actingAs($test->verifier, 'api')->postJson("/api/v1/loans/{$id}/verify")->assertOk()->assertJsonPath('data.status', 'verified');
}

function loanToApproved($test, string $id, array $body = []): void
{
    loanToVerified($test, $id);
    $test->actingAs($test->approver, 'api')->postJson("/api/v1/loans/{$id}/approve", $body)->assertOk()->assertJsonPath('data.status', 'approved');
}

/**
 * The officer records that every borrowing party accepted the offer (no code is involved).
 */
function loanAcceptOffer($test, string $id): void
{
    $loan = $test->actingAs($test->officer, 'api')->getJson("/api/v1/loans/{$id}")->json('data');
    $parties = collect($loan['parties'])->merge(collect($loan['member_loans'])->pluck('parties')->flatten(1))
        ->where('role', '!=', 'guarantor');

    foreach ($parties as $party) {
        $test->actingAs($test->officer, 'api')->postJson("/api/v1/loans/{$id}/parties/{$party['id']}/offer/accept")->assertOk();
    }
}

function loanDisburse($test, string $id, string $key = 'disb-key-0001')
{
    return $test->actingAs($test->disburser, 'api')
        ->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/loans/{$id}/disburse", ['channel' => 'bank_transfer', 'external_reference' => 'BOC-778812']);
}

function problemCodes($response): array
{
    return collect($response->json('details.problems'))->pluck('code')->all();
}

describe('applications', function () {
    it('creates an individual application as a draft with the borrower on it', function () {
        loanApply($this)->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.terms.rate', '18.0000')
            ->assertJsonPath('data.terms.cost_label', 'Interest')
            ->assertJsonPath('data.parties.0.role', 'borrower')
            ->assertJsonPath('data.application_no', 'APP-'.now()->format('ymd').'0001');
    });

    it('keeps the amount within the product band and the borrower type within the product', function () {
        loanApply($this, ['requested_amount' => '5000'])->assertStatus(422)->assertJsonPath('code', 'AMOUNT_OUT_OF_PRODUCT_LIMITS');
        loanApply($this, ['tenure' => 90])->assertStatus(422)->assertJsonPath('code', 'TENURE_OUT_OF_PRODUCT_LIMITS');
        loanApply($this, ['borrower_type' => 'group', 'borrower_group_public_id' => (string) str()->ulid(), 'members' => [['customer_public_id' => $this->borrower->public_id, 'amount' => '20000']]])
            ->assertStatus(422)->assertJsonPath('code', 'BORROWER_TYPE_NOT_ALLOWED');
    });

    it('needs confirmed KYC to submit (L-2)', function () {
        $this->borrower->forceFill(['kyc_status' => 'pending'])->save();
        $id = loanApply($this)->json('data.public_id');

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/submit")
            ->assertStatus(409)
            ->assertJsonPath('code', 'KYC_NOT_VERIFIED');
    });

    it('allows one open loan per person, and frees the person when the loan closes (L-1)', function () {
        $first = loanApply($this)->json('data.public_id');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$first}/submit")->assertOk();

        loanApply($this)->assertStatus(409)->assertJsonPath('code', 'BORROWER_HAS_OPEN_LOAN');

        // The database refuses it too, whatever the application code does.
        $second = Loan::forceCreate([
            'product_id' => $this->product->id, 'branch_id' => $this->branchId, 'borrower_type' => 'individual', 'customer_id' => $this->borrower->id,
            'requested_amount' => '10000', 'tenure' => 6, 'finance_basis' => 'conventional', 'pricing_method' => 'reducing_balance', 'repayment_frequency' => 'monthly', 'rate' => '18',
        ]);
        expect(fn () => LoanParty::forceCreate(['loan_id' => $second->id, 'customer_id' => $this->borrower->id, 'role' => 'borrower', 'open_loan_key' => $this->borrower->id]))
            ->toThrow(UniqueConstraintViolationException::class);

        $this->actingAs($this->reviewer, 'api')->postJson("/api/v1/loans/{$first}/start-review")->assertOk();
        $this->actingAs($this->reviewer, 'api')->postJson("/api/v1/loans/{$first}/reject", ['remarks' => 'Business not verifiable'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.closure_reason', 'rejected');

        loanApply($this)->assertCreated();
    });

    it('hides loans of other branches', function () {
        $id = loanApply($this)->json('data.public_id');
        $kandyOfficer = Fixtures::staffInBranch(Fixtures::branch('Kandy'), 'Loan Index');

        $this->actingAs($kandyOfficer, 'api')->getJson("/api/v1/loans/{$id}")->assertNotFound();
        $this->actingAs($kandyOfficer, 'api')->getJson('/api/v1/loans')->assertOk()->assertJsonPath('data.meta.total', 0);
    });
});

describe('workflow', function () {
    it('runs from application to disbursement with a different person at each step', function () {
        $id = loanApply($this)->json('data.public_id');

        loanToApproved($this, $id);
        loanAcceptOffer($this, $id);

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}")->assertJsonPath('data.status', 'accepted');

        $loan = Loan::where('public_id', $id)->first();
        LoanFixtures::confirmReview($loan);

        loanDisburse($this, $id)->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.loan_no', 'CDP-'.now()->format('ymd').'0001')
            ->assertJsonPath('data.balances.outstanding_principal', '150000.00');

        expect(LoanInstallment::where('loan_id', $loan->id)->count())->toBe(24)
            ->and((string) LoanInstallment::where('loan_id', $loan->id)->sum('principal_due'))->toBe('150000')
            ->and(LoanInstallment::where('loan_id', $loan->id)->orderBy('installment_no')->first()->due_date->toDateString())->toBe(today()->addMonthNoOverflow()->toDateString())
            ->and(LoanTransaction::where('loan_id', $loan->id)->where('type', 'disbursement')->value('amount'))->toBe('150000.00');

        // A retry with the same key returns the result again and never pays twice.
        loanDisburse($this, $id)->assertOk()->assertJsonPath('data.status', 'active');
        expect(LoanTransaction::where('loan_id', $loan->id)->where('type', 'disbursement')->count())->toBe(1);

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}/schedule")
            ->assertOk()
            ->assertJsonPath('data.is_preview', false)
            ->assertJsonCount(24, 'data.schedule');

        $timeline = collect($this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}/activities?per_page=100")->json('data.items'))->pluck('to_status')->filter()->values()->all();
        expect($timeline)->toBe(['active', 'accepted', 'approved', 'verified', 'in_review', 'submitted']);
    });

    it('lets the permissions alone decide: one person whose role holds them can take a loan through every step', function () {
        $id = loanApply($this)->json('data.public_id');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/submit")->assertOk();

        // Without the permission a step is refused, whoever the person is.
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/start-review")->assertForbidden();

        $everything = LoanFixtures::approver($this->branchId, 'Loan Review', 'Loan Verify', 'Loan Disburse', 'Loan Offer Manage');
        $this->actingAs($everything, 'api')->postJson("/api/v1/loans/{$id}/start-review")->assertOk();
        $this->actingAs($everything, 'api')->postJson("/api/v1/loans/{$id}/verify")->assertOk();
        $this->actingAs($everything, 'api')->postJson("/api/v1/loans/{$id}/approve")->assertOk();

        loanAcceptOffer($this, $id);
        LoanFixtures::confirmReview(Loan::where('public_id', $id)->first());

        $this->actingAs($everything, 'api')->withHeader('Idempotency-Key', 'disb-key-0002')
            ->postJson("/api/v1/loans/{$id}/disburse", ['channel' => 'cash'])
            ->assertOk()->assertJsonPath('data.status', 'active');
    });

    it('needs only the approve permission (no per-role limit) and never approves above the request', function () {
        $id = loanApply($this)->json('data.public_id');
        loanToVerified($this, $id);

        $noPermission = Fixtures::staffInBranch($this->branchId, 'Loan Index');
        $this->actingAs($noPermission, 'api')->postJson("/api/v1/loans/{$id}/approve")->assertForbidden();

        $this->actingAs($this->approver, 'api')->postJson("/api/v1/loans/{$id}/approve", ['approved_amount' => '200000'])
            ->assertStatus(422)->assertJsonPath('code', 'APPROVED_ABOVE_REQUESTED');

        $this->actingAs($this->approver, 'api')->postJson("/api/v1/loans/{$id}/approve", ['rate' => '30'])
            ->assertStatus(422)->assertJsonPath('code', 'RATE_OUT_OF_PRODUCT_LIMITS');

        $this->actingAs($this->approver, 'api')->postJson("/api/v1/loans/{$id}/approve", ['approved_amount' => '120000', 'rate' => '20'])
            ->assertOk()
            ->assertJsonPath('data.terms.approved_amount', '120000.00')
            ->assertJsonPath('data.terms.rate', '20.0000')
            ->assertJsonPath('data.amount', '120000.00');
    });

    it('lists every problem at verification: documents and affordability', function () {
        $poor = LoanFixtures::customer($this->branchId, [], withDocuments: false);
        LoanFixtures::income($poor, '20000', '9000');

        $id = loanApply($this, ['customer_public_id' => $poor->public_id])->json('data.public_id');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/submit")->assertOk();
        $this->actingAs($this->reviewer, 'api')->postJson("/api/v1/loans/{$id}/start-review")->assertOk();

        $response = $this->actingAs($this->verifier, 'api')->postJson("/api/v1/loans/{$id}/verify")
            ->assertStatus(422)
            ->assertJsonPath('code', 'LOAN_NOT_READY');

        expect(problemCodes($response))->toContain('DOCUMENTS_INCOMPLETE', 'DSR_TOO_HIGH');
    });

    it('lets the reviewer return an application for correction and the officer resubmit it', function () {
        $id = loanApply($this)->json('data.public_id');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/submit")->assertOk();
        $this->actingAs($this->reviewer, 'api')->postJson("/api/v1/loans/{$id}/start-review")->assertOk();

        $this->actingAs($this->reviewer, 'api')->postJson("/api/v1/loans/{$id}/return")->assertStatus(422);
        $this->actingAs($this->reviewer, 'api')->postJson("/api/v1/loans/{$id}/return", ['remarks' => 'Tenure too long for the purpose'])->assertOk()
            ->assertJsonPath('data.status', 'returned');

        // Returned applications change freely; after the next submission a change needs a reason (see FullCrudTest).
        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$id}", ['tenure' => 12])->assertOk()->assertJsonPath('data.terms.tenure', 12);
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->actingAs($this->officer, 'api')->putJson("/api/v1/loans/{$id}", ['tenure' => 18])->assertStatus(422)->assertJsonPath('code', 'EDIT_REASON_REQUIRED');
    });

    it('records the borrower acceptance without sending any code', function () {
        $id = loanApply($this)->json('data.public_id');
        loanToApproved($this, $id);

        $partyId = LoanParty::where('role', 'borrower')->value('id');
        $this->sms->sent = [];

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/parties/{$partyId}/offer/accept", ['remarks' => 'Signed the offer letter at the branch'])
            ->assertOk()->assertJsonPath('data.status', 'accepted');

        expect($this->sms->sent)->toBe([])
            ->and(LoanParty::find($partyId)->consented_at)->not->toBeNull();

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}/activities")->assertOk()
            ->assertJsonFragment(['remarks' => 'Offer accepted by '.LoanParty::find($partyId)->customer->customer_no.': Signed the offer letter at the branch']);

        // A borrower accepts once.
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/parties/{$partyId}/offer/accept")->assertStatus(409);
    });

    it('lets the approver lower the amount, by percentage, and the term', function () {
        $id = loanApply($this)->json('data.public_id');
        loanToVerified($this, $id);

        // More than asked for, or a longer term than asked for, is not what the customer applied for.
        $this->actingAs($this->approver, 'api')->postJson("/api/v1/loans/{$id}/approve", ['tenure' => 36])->assertStatus(422)->assertJsonPath('code', 'APPROVED_TENURE_ABOVE_REQUESTED');
        $this->actingAs($this->approver, 'api')->postJson("/api/v1/loans/{$id}/approve", ['approved_amount' => '100000', 'approved_percent' => '50'])->assertStatus(422);

        $this->actingAs($this->approver, 'api')->postJson("/api/v1/loans/{$id}/approve", ['approved_percent' => '60', 'tenure' => 12, 'remarks' => 'Shorter term, smaller amount'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.terms.approved_amount', '90000.00')
            ->assertJsonPath('data.terms.requested_amount', '150000.00')
            ->assertJsonPath('data.terms.tenure', 12);
    });

    it('allows more than was asked for, or outside the product limits, only as a special approval for that application', function () {
        $id = loanApply($this)->json('data.public_id');
        loanToVerified($this, $id);

        // Without the flag nothing changes: the request is the ceiling.
        $this->actingAs($this->approver, 'api')->postJson("/api/v1/loans/{$id}/approve", ['approved_amount' => '180000'])->assertStatus(422)->assertJsonPath('code', 'APPROVED_ABOVE_REQUESTED');

        // The flag needs the permission and a written reason.
        $this->actingAs($this->approver, 'api')->postJson("/api/v1/loans/{$id}/approve", ['approved_amount' => '180000', 'special_approval' => true, 'remarks' => 'Owner asked for more by phone'])
            ->assertStatus(403)->assertJsonPath('code', 'SPECIAL_APPROVAL_NOT_ALLOWED');

        $special = LoanFixtures::approver($this->branchId, 'Loan Index', 'Loan Approve Special');
        $this->actingAs($special, 'api')->postJson("/api/v1/loans/{$id}/approve", ['approved_amount' => '180000', 'special_approval' => true])
            ->assertStatus(422)->assertJsonPath('code', 'SPECIAL_APPROVAL_REASON_REQUIRED');

        $this->actingAs($special, 'api')->postJson("/api/v1/loans/{$id}/approve", ['approved_amount' => '180000', 'tenure' => 36, 'special_approval' => true, 'remarks' => 'Owner asked for more by phone'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.terms.requested_amount', '150000.00')
            ->assertJsonPath('data.terms.approved_amount', '180000.00')
            ->assertJsonPath('data.terms.tenure', 36);

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}/activities")->assertOk()->assertJsonFragment(['remarks' => 'Owner asked for more by phone']);

        // The next application is checked as usual.
        $other = LoanFixtures::customer($this->branchId);
        LoanFixtures::income($other, '150000');
        $next = loanApply($this, ['customer_public_id' => $other->public_id])->json('data.public_id');
        loanToVerified($this, $next);
        $this->actingAs($special, 'api')->postJson("/api/v1/loans/{$next}/approve", ['approved_amount' => '180000'])->assertStatus(422)->assertJsonPath('code', 'APPROVED_ABOVE_REQUESTED');
    });

    it('records what the customer answers after approval: accept, put on hold or decline, each with a reason', function () {
        $id = loanApply($this)->json('data.public_id');
        loanToApproved($this, $id);

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/offer/hold")->assertStatus(422);
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/offer/hold", ['remarks' => 'Wants to discuss with the family first'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.offer.on_hold', true)->assertJsonPath('data.offer.remarks', 'Wants to discuss with the family first');

        // Accepting later clears the hold.
        $partyId = LoanParty::where('role', 'borrower')->value('id');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/parties/{$partyId}/offer/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}")->assertJsonPath('data.offer.on_hold', false);
    });

    it('cancels the loan when the customer declines, and needs the reason', function () {
        $id = loanApply($this)->json('data.public_id');
        loanToApproved($this, $id);

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/offer/decline")->assertStatus(422);
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/offer/decline", ['remarks' => 'Found a cheaper loan elsewhere'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.offer.remarks', 'Found a cheaper loan elsewhere');

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/offer/hold", ['remarks' => 'Too late now'])->assertStatus(409)->assertJsonPath('code', 'OFFER_NOT_OPEN');
    });

    it('does not disburse before the customer confirms the review, or without an idempotency key', function () {
        $id = loanApply($this)->json('data.public_id');
        loanToApproved($this, $id);
        loanAcceptOffer($this, $id);

        $this->actingAs($this->disburser, 'api')->postJson("/api/v1/loans/{$id}/disburse", ['channel' => 'cash'])
            ->assertStatus(422)->assertJsonFragment(['field' => 'idempotency_key']);

        $response = loanDisburse($this, $id)->assertStatus(422)->assertJsonPath('code', 'LOAN_NOT_READY');
        expect(problemCodes($response))->toBe(['REVIEW_NOT_CONFIRMED']);
    });
});

describe('guarantors', function () {
    it('limits a person to two open guarantees (G-2)', function () {
        $guarantor = LoanFixtures::customer($this->branchId);

        foreach (range(1, 3) as $n) {
            $borrower = LoanFixtures::customer($this->branchId);
            $id = loanApply($this, ['customer_public_id' => $borrower->public_id])->json('data.public_id');

            $response = $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/guarantors", ['customer_public_id' => $guarantor->public_id, 'relationship' => 'friend']);

            $n < 3
                ? $response->assertCreated()
                : $response->assertStatus(409)->assertJsonPath('code', 'GUARANTOR_LIMIT_REACHED');
        }
    });

    it('does not let a borrower guarantee their own loan (G-4)', function () {
        $id = loanApply($this)->json('data.public_id');

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/guarantors", ['customer_public_id' => $this->borrower->public_id])
            ->assertStatus(409)->assertJsonPath('code', 'GUARANTOR_IS_PARTY');
    });

    it('blocks a guarantor who paid for a borrower until that loan is settled (G-3)', function () {
        $guarantor = LoanFixtures::customer($this->branchId);
        $id = loanApply($this)->json('data.public_id');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/guarantors", ['customer_public_id' => $guarantor->public_id])->assertCreated();

        // Phase 6 sets this when a guarantor's payment is posted.
        LoanParty::where('customer_id', $guarantor->id)->first()->forceFill(['invoked_at' => now()])->save();

        loanApply($this, ['customer_public_id' => $guarantor->public_id])->assertStatus(409)->assertJsonPath('code', 'GUARANTOR_INVOKED_BLOCKED');

        $other = loanApply($this, ['customer_public_id' => LoanFixtures::customer($this->branchId)->public_id])->json('data.public_id');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$other}/guarantors", ['customer_public_id' => $guarantor->public_id])
            ->assertStatus(409)->assertJsonPath('code', 'GUARANTOR_INVOKED_BLOCKED');

        Loan::where('public_id', $id)->first()->forceFill(['status' => 'settled'])->save();
        loanApply($this, ['customer_public_id' => $guarantor->public_id])->assertCreated();
    });
});

describe('collateral', function () {
    beforeEach(function () {
        $this->gold = LoanFixtures::product('gold-pawning', ['default_rate' => '24', 'min_rate' => '20', 'max_rate' => '30', 'min_tenure' => 1, 'max_tenure' => 12]);
        $this->gold->collateralRules()->create(['collateral_type' => 'gold', 'max_ltv_percent' => '70', 'is_mandatory' => true]);
    });

    function goldApply($test, string $amount): string
    {
        return loanApply($test, ['product_slug' => 'gold-pawning', 'requested_amount' => $amount, 'tenure' => 6])->assertCreated()->json('data.public_id');
    }

    function addGold($test, string $id)
    {
        return $test->actingAs($test->officer, 'api')->postJson("/api/v1/loans/{$id}/collaterals", [
            'type' => 'gold',
            'description' => 'Two 22K bangles',
            'details' => ['item' => 'Bangle x2', 'karat' => 22, 'gross_weight_g' => '10.500', 'net_weight_g' => '10.000', 'rate_per_gram_24k' => '30000'],
            'valued_on' => today()->toDateString(),
            'valued_by' => 'Branch gold examiner',
        ]);
    }

    it('values gold from weight and purity, and needs the verify permission and the slip to verify it', function () {
        $id = goldApply($this, '150000');

        // 10 g × 22/24 × 30,000 = 275,000; at 70% LTV it secures 192,500.
        $collateralId = addGold($this, $id)->assertCreated()
            ->assertJsonPath('data.market_value', '275000.00')
            ->assertJsonPath('data.eligible_amount', '192500.00')
            ->json('data.public_id');

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/collaterals/{$collateralId}/verify")->assertForbidden();

        $this->actingAs($this->verifier, 'api')->postJson("/api/v1/loans/{$id}/collaterals/{$collateralId}/verify")
            ->assertStatus(422)->assertJsonPath('code', 'COLLATERAL_DOCUMENTS_MISSING');

        LoanFixtures::verifiedDocument(Collateral::where('public_id', $collateralId)->first(), 'gold-valuation-slip');

        $this->actingAs($this->verifier, 'api')->postJson("/api/v1/loans/{$id}/collaterals/{$collateralId}/verify")
            ->assertOk()->assertJsonPath('data.status', 'verified');
    });

    it('needs only the title document of an asset; the valuation report is optional', function () {
        $id = loanApply($this)->assertCreated()->json('data.public_id');

        $collateralId = $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/collaterals", [
            'type' => 'property_mortgage', 'description' => 'House in Maharagama', 'details' => ['deed_no' => 'D 1234/56'],
            'market_value' => '8000000', 'valued_on' => today()->toDateString(),
        ])->assertCreated()->json('data.public_id');

        $checklist = fn () => collect($this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}/document-checklist")->json('data.collaterals'))->firstWhere('public_id', $collateralId);

        expect($checklist()['missing_documents'])->toBe(['property-deed'])
            ->and($checklist()['optional_documents'])->toBe(['valuation-report'])
            ->and($checklist()['complete'])->toBeFalse();

        LoanFixtures::verifiedDocument(Collateral::where('public_id', $collateralId)->first(), 'property-deed');

        expect($checklist()['missing_documents'])->toBe([])->and($checklist()['complete'])->toBeTrue();
        $this->actingAs($this->verifier, 'api')->postJson("/api/v1/loans/{$id}/collaterals/{$collateralId}/verify")->assertOk()->assertJsonPath('data.status', 'verified');
    });

    it('returns the collateral\'s uploaded documents with it, so the screen can show them', function () {
        $id = goldApply($this, '150000');
        $collateralId = addGold($this, $id)->assertCreated()->json('data.public_id');

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}/collaterals")->assertOk()->assertJsonPath('data.0.documents', []);

        LoanFixtures::verifiedDocument(Collateral::where('public_id', $collateralId)->first(), 'gold-valuation-slip');

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}/collaterals")->assertOk()
            ->assertJsonPath('data.0.documents.0.document_type.slug', 'gold-valuation-slip')
            ->assertJsonPath('data.0.documents.0.status', 'verified');
        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$id}")->assertOk()
            ->assertJsonPath('data.collaterals.0.documents.0.document_type.slug', 'gold-valuation-slip');
        $this->actingAs($this->verifier, 'api')->postJson("/api/v1/loans/{$id}/collaterals/{$collateralId}/verify")
            ->assertOk()->assertJsonCount(1, 'data.documents');
    });

    it('does not limit the loan by what the collateral is worth: the approver decides the amount', function () {
        $id = goldApply($this, '250000');
        $collateralId = addGold($this, $id)->json('data.public_id');
        LoanFixtures::verifiedDocument(Collateral::where('public_id', $collateralId)->first(), 'gold-valuation-slip');
        $this->actingAs($this->verifier, 'api')->postJson("/api/v1/loans/{$id}/collaterals/{$collateralId}/verify")->assertOk();

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/submit")->assertOk();
        $this->actingAs($this->reviewer, 'api')->postJson("/api/v1/loans/{$id}/start-review")->assertOk();

        // The gold secures far less than the 250 000 asked for, and that is not a reason to refuse.
        $this->actingAs($this->verifier, 'api')->postJson("/api/v1/loans/{$id}/verify")->assertOk()->assertJsonPath('data.status', 'verified');
        $this->actingAs($this->approver, 'api')->postJson("/api/v1/loans/{$id}/approve", ['approved_amount' => '250000'])->assertOk()->assertJsonPath('data.status', 'approved');
    });

    it('disburses a gold loan only after the gold is in custody', function () {
        $id = goldApply($this, '150000');
        $collateralId = addGold($this, $id)->json('data.public_id');
        LoanFixtures::verifiedDocument(Collateral::where('public_id', $collateralId)->first(), 'gold-valuation-slip');
        $this->actingAs($this->verifier, 'api')->postJson("/api/v1/loans/{$id}/collaterals/{$collateralId}/verify")->assertOk();

        loanToApproved($this, $id);
        loanAcceptOffer($this, $id);
        LoanFixtures::confirmReview(Loan::where('public_id', $id)->first());

        expect(problemCodes(loanDisburse($this, $id)->assertStatus(422)))->toBe(['COLLATERAL_NOT_PERFECTED']);

        $this->actingAs($this->approver, 'api')->postJson("/api/v1/loans/{$id}/collaterals/{$collateralId}/perfect")
            ->assertStatus(422)->assertJsonPath('code', 'COLLATERAL_REFERENCE_REQUIRED');
        $this->actingAs($this->approver, 'api')->postJson("/api/v1/loans/{$id}/collaterals/{$collateralId}/perfect", ['custody_ref' => 'PKT-0042 / Vault A'])
            ->assertOk()->assertJsonPath('data.status', 'perfected');

        loanDisburse($this, $id)->assertOk()->assertJsonPath('data.status', 'active');

        // Gold pawning is repaid in one go at maturity.
        expect(LoanInstallment::where('loan_id', Loan::where('public_id', $id)->value('id'))->count())->toBe(1);
    });
});

describe('group loans', function () {
    beforeEach(function () {
        LoanFixtures::product('womens-empowerment-micro-loan', ['default_rate' => '20', 'min_rate' => '15', 'max_rate' => '25', 'min_amount' => '10000', 'max_amount' => '100000']);

        $this->groupAdmin = Fixtures::staffInBranch($this->branchId, 'Borrower Group Create', 'Borrower Group Member Create', 'Borrower Group Member Update', 'Borrower Group Member Delete');
        $this->group = $this->actingAs($this->groupAdmin, 'api')
            ->postJson('/api/v1/borrower-groups', ['name' => 'Sunrise Women', 'branch_id' => $this->branchId, 'formed_on' => '2026-01-10'])
            ->json('data.public_id');

        $this->members = collect(range(1, 3))->map(fn () => LoanFixtures::customer($this->branchId, ['gender' => 'female']));

        foreach ($this->members as $member) {
            $this->actingAs($this->groupAdmin, 'api')->postJson("/api/v1/borrower-groups/{$this->group}/members", ['customer_public_id' => $member->public_id])->assertCreated();
        }
    });

    function groupApply($test)
    {
        return $test->actingAs($test->officer, 'api')->postJson('/api/v1/loans', [
            'product_slug' => 'womens-empowerment-micro-loan',
            'borrower_type' => 'group',
            'borrower_group_public_id' => $test->group,
            'tenure' => 12,
            'members' => $test->members->map(fn (Customer $c) => ['customer_public_id' => $c->public_id, 'amount' => '25000'])->all(),
        ]);
    }

    function addGroupMember($test)
    {
        $newcomer = LoanFixtures::customer($test->branchId, ['gender' => 'female']);

        return $test->actingAs($test->groupAdmin, 'api')->postJson("/api/v1/borrower-groups/{$test->group}/members", ['customer_public_id' => $newcomer->public_id]);
    }

    it('gives every member their own loan and moves them together', function () {
        $id = groupApply($this)->assertCreated()
            ->assertJsonPath('data.borrower_type', 'group')
            ->assertJsonPath('data.terms.requested_amount', '75000.00')
            ->assertJsonCount(3, 'data.member_loans')
            ->json('data.public_id');

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/submit")->assertOk();

        $memberLoan = Loan::whereNotNull('parent_id')->first();
        expect(Loan::whereNotNull('parent_id')->pluck('status')->map->value->unique()->all())->toBe(['submitted']);

        // Actions are taken on the facility, never on a member loan.
        $this->actingAs($this->reviewer, 'api')->postJson("/api/v1/loans/{$memberLoan->public_id}/start-review")
            ->assertStatus(422)->assertJsonPath('code', 'MEMBER_LOAN_ACTION');
    });

    it('freezes membership from approval until the loan closes (GR-1 to GR-3)', function () {
        $id = groupApply($this)->json('data.public_id');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/submit")->assertOk();

        // GR-3: waiting for approval, the group can still take members.
        addGroupMember($this)->assertCreated();

        $this->actingAs($this->reviewer, 'api')->postJson("/api/v1/loans/{$id}/start-review")->assertOk();
        $this->actingAs($this->verifier, 'api')->postJson("/api/v1/loans/{$id}/verify")->assertOk();
        $this->actingAs($this->approver, 'api')->postJson("/api/v1/loans/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        // GR-1: approved, so membership is frozen.
        addGroupMember($this)->assertStatus(409)->assertJsonPath('code', 'GROUP_HAS_ACTIVE_LOAN');

        // GR-2: once the loan is closed members can change again.
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/cancel", ['remarks' => 'Group postponed the loan'])->assertOk();
        addGroupMember($this)->assertCreated();
    });

    it('does not let a member guarantee the group loan (GR-7)', function () {
        LoanFixtures::product('womens-empowerment-micro-loan', ['default_rate' => '20', 'min_rate' => '15', 'max_rate' => '25', 'max_guarantors' => 1]);
        $id = groupApply($this)->json('data.public_id');

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/guarantors", ['customer_public_id' => $this->members[0]->public_id])
            ->assertStatus(409)->assertJsonPath('code', 'GUARANTOR_IS_PARTY');

        $outsider = LoanFixtures::customer($this->branchId);
        $this->actingAs($this->groupAdmin, 'api')->postJson("/api/v1/borrower-groups/{$this->group}/members", ['customer_public_id' => $outsider->public_id])->assertCreated();

        // A current member who is not (yet) on the application is still a member of the group.
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$id}/guarantors", ['customer_public_id' => $outsider->public_id])
            ->assertStatus(422)->assertJsonPath('code', 'GROUP_MEMBER_CANNOT_GUARANTEE');
    });
});

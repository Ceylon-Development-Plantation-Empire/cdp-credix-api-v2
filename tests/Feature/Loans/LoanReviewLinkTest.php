<?php

declare(strict_types=1);

use App\Exceptions\BusinessRuleException;
use App\Models\Loan;
use App\Models\LoanParty;
use App\Models\Setting;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\TemplateSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;
use App\Services\Loans\DisbursementService;

/*
| The customer review link: one SMS link and one code per borrower; the customer can ask for
| corrections; an officer can help the customer confirm; a change after confirming asks again.
*/

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class, TemplateSeeder::class, SettingsSeeder::class]);
    $this->sms = Fixtures::fakeSms();
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);
    $this->officer = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Offer Manage');
    // The customer confirms after accepting the approved offer, before the payout.
    $this->loan = LoanFixtures::acceptedLoan($this->branchId, 'sme-loan', '150000', 12);
    $this->loanId = $this->loan->public_id;
    $this->customerPublicId = $this->loan->customer->public_id;
});

function reviewLink($test): string
{
    $response = $test->actingAs($test->officer, 'api')->postJson("/api/v1/loans/{$test->loanId}/review/send-link")->assertOk();

    return basename((string) $response->json('data.customers.0.dev_link'));
}

describe('sending the link', function () {
    it('sends one SMS with the link and no code', function () {
        $response = $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$this->loanId}/review/send-link")
            ->assertOk()
            ->assertJsonPath('data.customers.0.status', 'pending')
            ->assertJsonPath('data.customers.0.sms_sent', true);

        $token = basename($response->json('data.customers.0.dev_link'));

        expect($this->sms->sent)->toHaveCount(1)
            ->and($this->sms->sent[0]['message'])->toContain("/loan-review/{$token}")
            ->and($this->sms->sent[0]['message'])->not->toContain('is your code');

        // Only the hash of the token is stored.
        $party = LoanParty::where('role', 'borrower')->first();
        expect($party->review_token_hash)->toBe(hash('sha256', $token));
    });

    it('shows each borrower\'s state to the officer', function () {
        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$this->loanId}/review")
            ->assertOk()->assertJsonPath('data.all_confirmed', false)->assertJsonPath('data.customers.0.status', 'not_sent');

        reviewLink($this);

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$this->loanId}/review")
            ->assertOk()->assertJsonPath('data.customers.0.status', 'pending')->assertJsonPath('data.customers.0.customer_phone_masked', fn ($v) => str_contains($v, '*'));
    });

    it('refuses a draft application and a customer from another branch', function () {
        Loan::where('public_id', $this->loanId)->first()->forceFill(['status' => 'draft'])->save();

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$this->loanId}/review/send-link")
            ->assertStatus(409)->assertJsonPath('code', 'REVIEW_NOT_OPEN');

        $other = Fixtures::staffInBranch(Fixtures::branch('Kandy'), 'Loan Index', 'Loan Offer Manage');
        $this->actingAs($other, 'api')->getJson("/api/v1/loans/{$this->loanId}/review")->assertNotFound();
    });
});

describe('the customer on the link', function () {
    it('reads the details masked, asks for one code, and confirms', function () {
        $token = reviewLink($this);
        $this->sms->sent = [];

        $data = $this->getJson("/api/v1/public/loan-review/{$token}")->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.details.loan.reference', $this->loan->application_no)
            ->assertJsonPath('data.details.loan.requested_amount', '150000.00')
            ->json('data.details');

        expect(str_contains($data['kyc']['id_number'], '*'))->toBeTrue()
            ->and(str_contains($data['kyc']['phone_primary'], '*'))->toBeTrue();

        // The customer's own staff page now shows the link was opened.
        expect(LoanParty::where('role', 'borrower')->first()->review_viewed_at)->not->toBeNull();

        $this->postJson("/api/v1/public/loan-review/{$token}/request-otp")->assertOk()
            ->assertJsonPath('data.otp_requests_left', 2)->assertJsonMissingPath('data.dev_otp');
        $code = $this->sms->lastCode();
        expect($code)->not->toBeNull();

        $this->postJson("/api/v1/public/loan-review/{$token}/confirm", ['otp' => $code, 'declaration_accepted' => false])->assertStatus(422);

        $this->postJson("/api/v1/public/loan-review/{$token}/confirm", ['otp' => $code === '000000' ? '111111' : '000000', 'declaration_accepted' => true])
            ->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');

        $this->postJson("/api/v1/public/loan-review/{$token}/confirm", ['otp' => $code, 'declaration_accepted' => true])
            ->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.reference', fn ($v) => str_starts_with($v, 'CONF-'));

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$this->loanId}/review")
            ->assertOk()->assertJsonPath('data.all_confirmed', true)->assertJsonPath('data.customers.0.confirmed_via', 'link');
        expect($this->officer->notifications()->where('data->type', 'LOAN_REVIEW_CONFIRMED')->count())->toBe(1);

        // One confirmation only, and a second code is never needed.
        $this->postJson("/api/v1/public/loan-review/{$token}/confirm", ['otp' => $code, 'declaration_accepted' => true])
            ->assertStatus(409)->assertJsonPath('code', 'REVIEW_ALREADY_CONFIRMED');
        expect($this->sms->sent)->toHaveCount(1);
    });

    it('limits how often a code can be sent for one link', function () {
        $token = reviewLink($this);

        $this->postJson("/api/v1/public/loan-review/{$token}/request-otp")->assertOk();
        // Three SMS in total (the first request plus two resends); then the limit answers.
        $this->postJson("/api/v1/public/loan-review/{$token}/request-otp")->assertOk()->assertJsonPath('data.otp_requests_left', 1);
        $this->postJson("/api/v1/public/loan-review/{$token}/request-otp")->assertOk()->assertJsonPath('data.otp_requests_left', 0);
        $this->postJson("/api/v1/public/loan-review/{$token}/request-otp")->assertStatus(429);
    });

    it('does not open for a wrong or replaced link', function () {
        $this->getJson('/api/v1/public/loan-review/'.str_repeat('a', 48))->assertNotFound();
        $this->getJson('/api/v1/public/loan-review/short')->assertNotFound();

        $first = reviewLink($this);
        $second = reviewLink($this);
        expect($second)->not->toBe($first);
        $this->getJson("/api/v1/public/loan-review/{$first}")->assertNotFound();
        $this->getJson("/api/v1/public/loan-review/{$second}")->assertOk();
    });

    it('is spent by opens, not by time', function () {
        Setting::where('key', 'loan_review.max_opens')->firstOrFail()->forceFill(['value' => '2'])->save();
        $token = reviewLink($this);

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$this->loanId}/review")->assertJsonPath('data.customers.0.opens_left', 2);

        $this->getJson("/api/v1/public/loan-review/{$token}")->assertOk()->assertJsonPath('data.opens_left', 1);
        $this->getJson("/api/v1/public/loan-review/{$token}")->assertOk()->assertJsonPath('data.opens_left', 0);
        $this->getJson("/api/v1/public/loan-review/{$token}")->assertStatus(410)->assertJsonPath('code', 'REVIEW_LINK_EXPIRED');

        // Someone already on the page can still ask for the code.
        $this->postJson("/api/v1/public/loan-review/{$token}/request-otp")->assertOk();

        // A new link starts the count again; and a time passing changes nothing.
        $this->travel(30)->days();
        $fresh = reviewLink($this);
        $this->getJson("/api/v1/public/loan-review/{$fresh}")->assertOk()->assertJsonPath('data.opens_left', 1);
    });

    it('reads only real columns of the collateral when it works out what the customer confirms', function () {
        // collaterals has collateral_type_id, not a type column (type is the code of that lookup). SQLite quietly reads an unknown
        // quoted name as text, MySQL stops with "Unknown column", so the SQL itself is checked.
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $token = reviewLink($this);
        $this->postJson("/api/v1/public/loan-review/{$token}/request-otp")->assertOk();

        $collateralReads = array_filter($queries, fn (string $sql) => str_contains($sql, 'collaterals') && stripos($sql, 'select') === 0);

        expect($collateralReads)->not->toBeEmpty();

        foreach ($collateralReads as $sql) {
            expect($sql)->not->toMatch('/select\s+[`"]type[`"]/i');
        }
    });

    it('asks for the confirmation again when the details change afterwards', function () {
        $token = reviewLink($this);
        $this->postJson("/api/v1/public/loan-review/{$token}/request-otp")->assertOk();
        $this->postJson("/api/v1/public/loan-review/{$token}/confirm", ['otp' => $this->sms->lastCode(), 'declaration_accepted' => true])->assertOk();

        LoanParty::where('role', 'borrower')->first()->customer->forceFill(['occupation' => 'Engineer'])->save();

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$this->loanId}/review")
            ->assertOk()->assertJsonPath('data.all_confirmed', false)->assertJsonPath('data.customers.0.status', 'stale');
        $this->getJson("/api/v1/public/loan-review/{$token}")->assertStatus(410)->assertJsonPath('code', 'REVIEW_DETAILS_CHANGED');

        $fresh = reviewLink($this);
        $this->getJson("/api/v1/public/loan-review/{$fresh}")->assertOk()->assertJsonPath('data.status', 'pending');
    });

    it('does not accept a code that was sent for details that changed meanwhile', function () {
        $token = reviewLink($this);
        $this->postJson("/api/v1/public/loan-review/{$token}/request-otp")->assertOk();
        $code = $this->sms->lastCode();

        LoanParty::where('role', 'borrower')->first()->customer->forceFill(['occupation' => 'Engineer'])->save();

        $this->postJson("/api/v1/public/loan-review/{$token}/confirm", ['otp' => $code, 'declaration_accepted' => true])
            ->assertStatus(422)->assertJsonPath('code', 'OTP_PAYLOAD_CHANGED');
    });
});

describe('corrections', function () {
    it('lets the customer ask and the officer answer', function () {
        $token = reviewLink($this);

        $id = $this->postJson("/api/v1/public/loan-review/{$token}/correction-request", [
            'stage' => 'Stage 1 — Personal Details', 'field_name' => 'occupation', 'current_value' => 'Trader', 'proposed_value' => 'Shop owner', 'reason' => 'It is a shop',
        ])->assertCreated()->assertJsonPath('data.status', 'pending_review')->json('data.id');

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$this->loanId}/review")
            ->assertJsonPath('data.customers.0.open_correction_requests', 1);

        $this->getJson("/api/v1/public/loan-review/{$token}")->assertJsonPath('data.corrections.0.status', 'pending_review');

        // The officers see it as a system notification, with references only.
        $alert = $this->officer->notifications()->where('data->type', 'LOAN_CORRECTION_REQUESTED')->first();
        expect($alert)->not->toBeNull()
            ->and($alert->data['loan_public_id'])->toBe($this->loanId)
            ->and($alert->data['field_name'])->toBe('occupation');

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$this->loanId}/review/corrections")
            ->assertOk()->assertJsonPath('data.0.field_name', 'occupation')->assertJsonPath('data.0.proposed_value', 'Shop owner')->assertJsonPath('data.0.status', 'pending_review');

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$this->loanId}/review/corrections/{$id}/resolve", ['outcome' => 'accepted'])->assertStatus(422);
        $this->sms->sent = [];
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$this->loanId}/review/corrections/{$id}/resolve", ['outcome' => 'accepted', 'remarks' => 'Updated the occupation'])
            ->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.resolution_remarks', 'Updated the occupation')->assertJsonPath('data.new_link_sent', true);

        // The customer gets a new link to read the corrected details; the old one is dead.
        expect($this->sms->sent)->toHaveCount(1)->and($this->sms->sent[0]['message'])->toContain('/loan-review/')->and($this->sms->sent[0]['message'])->not->toContain($token);
        $this->getJson("/api/v1/public/loan-review/{$token}")->assertNotFound();

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$this->loanId}/review/corrections/{$id}/resolve", ['outcome' => 'declined', 'remarks' => 'No, as it is'])
            ->assertStatus(409)->assertJsonPath('code', 'CORRECTION_ALREADY_ANSWERED');

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$this->loanId}/review")
            ->assertJsonPath('data.customers.0.open_correction_requests', 0);
    });

    it('stops one link from flooding the officer with requests', function () {
        $token = reviewLink($this);
        $body = ['stage' => 'Stage 1', 'field_name' => 'occupation', 'proposed_value' => 'x', 'reason' => 'because'];

        foreach (range(1, 10) as $i) {
            $this->postJson("/api/v1/public/loan-review/{$token}/correction-request", $body)->assertCreated();
        }

        $this->postJson("/api/v1/public/loan-review/{$token}/correction-request", $body)->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_CORRECTIONS');
    });
});

describe('officer assisted confirmation', function () {
    it('shows the officer exactly what the customer sees, without using up an open', function () {
        $customerId = LoanParty::where('role', 'borrower')->first()->customer->public_id;
        $token = reviewLink($this);

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$this->loanId}/review/customers/{$customerId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.opens_left', 3)
            ->assertJsonPath('data.details.loan.reference', $this->loan->application_no)
            ->assertJsonPath('data.declaration_text', fn ($v) => str_contains($v, 'I confirm'));

        expect(LoanParty::where('role', 'borrower')->first()->review_open_count)->toBe(0)->and($token)->not->toBe('');
        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$this->loanId}/review/customers/01JZZZZZZZZZZZZZZZZZZZZZZZ")->assertStatus(404);
    });

    it('confirms with the code the customer reads out, and records why', function () {
        $url = "/api/v1/loans/{$this->loanId}/review/customers/".LoanParty::where('role', 'borrower')->first()->customer->public_id;

        $this->actingAs($this->officer, 'api')->postJson("{$url}/send-otp", ['reason' => 'short'])->assertStatus(422);
        $this->actingAs($this->officer, 'api')->postJson("{$url}/send-otp", ['reason' => 'Customer has no smartphone'])
            ->assertOk()->assertJsonPath('data.sent_to', fn ($v) => str_contains($v, '*'));
        $code = $this->sms->lastCode();

        $this->actingAs($this->officer, 'api')->postJson("{$url}/verify-otp", ['otp' => $code])->assertStatus(422);
        $this->actingAs($this->officer, 'api')->postJson("{$url}/verify-otp", ['otp' => $code, 'declaration_accepted' => true])
            ->assertOk()->assertJsonPath('data.status', 'confirmed');

        $party = LoanParty::where('role', 'borrower')->first();
        expect($party->review_confirmed_via)->toBe('staff')->and($party->review_reason)->toBe('Customer has no smartphone');

        $this->actingAs($this->officer, 'api')->postJson("{$url}/send-otp", ['reason' => 'Customer has no smartphone'])->assertStatus(409);
    });

    it('needs the loan offer permission', function () {
        $viewer = Fixtures::staffInBranch($this->branchId, 'Loan Index');

        $this->actingAs($viewer, 'api')->getJson("/api/v1/loans/{$this->loanId}/review")->assertOk();
        $this->actingAs($viewer, 'api')->postJson("/api/v1/loans/{$this->loanId}/review/send-link")->assertForbidden();
    });
});

describe('before disbursement', function () {
    it('never pays out until every borrower has confirmed with their code, and pays out once they have', function () {
        $disburser = Fixtures::staffInBranch($this->branchId, 'Loan Disburse');
        $disburse = fn () => app(DisbursementService::class)->disburse($this->loan->refresh(), ['channel' => 'cash'], 'disb-review-0001', $disburser);

        try {
            $disburse();
            $this->fail('The loan was paid out without the customer confirmation.');
        } catch (BusinessRuleException $e) {
            expect($e->errorCode)->toBe('LOAN_NOT_READY')
                ->and(collect($e->context['problems'])->pluck('code')->all())->toBe(['REVIEW_NOT_CONFIRMED']);
        }

        LoanFixtures::confirmReview($this->loan);

        expect($disburse()->status->value)->toBe('active');
    });

    it('has no switch to turn the requirement off', function () {
        expect(Setting::where('key', 'loan_review.required_before_disbursement')->exists())->toBeFalse();

        // Even a leftover setting row saying "off" changes nothing.
        Setting::create(['key' => 'loan_review.required_before_disbursement', 'group' => 'loans', 'type' => 'boolean', 'value' => '0', 'rules' => ['value' => 'required|boolean'], 'label' => 'x']);
        $disburser = Fixtures::staffInBranch($this->branchId, 'Loan Disburse');

        expect(fn () => app(DisbursementService::class)->disburse($this->loan->refresh(), ['channel' => 'cash'], 'disb-review-0002', $disburser))
            ->toThrow(BusinessRuleException::class);
    });

    it('asks again when the terms changed after the customer confirmed', function () {
        LoanFixtures::confirmReview($this->loan);
        Loan::where('public_id', $this->loanId)->first()->forceFill(['tenure' => 18])->save();
        $disburser = Fixtures::staffInBranch($this->branchId, 'Loan Disburse');

        expect(fn () => app(DisbursementService::class)->disburse($this->loan->refresh(), ['channel' => 'cash'], 'disb-review-0003', $disburser))
            ->toThrow(BusinessRuleException::class);
    });
});

describe('when the link and the code are open', function () {
    it('only after the customer accepted the offer', function (string $status) {
        Loan::where('public_id', $this->loanId)->first()->forceFill(['status' => $status])->save();

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$this->loanId}/review/send-link")->assertStatus(409)->assertJsonPath('code', 'REVIEW_NOT_OPEN');
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$this->loanId}/review/customers/{$this->customerPublicId}/send-otp", ['reason' => 'The customer has no phone for the link'])->assertStatus(409)->assertJsonPath('code', 'REVIEW_NOT_OPEN');
    })->with(['draft', 'submitted', 'in_review', 'returned', 'verified', 'approved']);

    it('and sends once the offer is accepted', function () {
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$this->loanId}/review/send-link")->assertOk()->assertJsonPath('data.customers.0.status', 'pending');
    });
});

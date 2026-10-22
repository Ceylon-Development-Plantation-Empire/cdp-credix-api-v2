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
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

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
    $this->loan = LoanFixtures::approvedLoan($this->branchId, 'sme-loan', '150000', 12);
    $this->loanId = $this->loan->public_id;
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

    it('does not open for a wrong, expired or replaced link', function () {
        $this->getJson('/api/v1/public/loan-review/'.str_repeat('a', 48))->assertNotFound();
        $this->getJson('/api/v1/public/loan-review/short')->assertNotFound();

        $first = reviewLink($this);
        $second = reviewLink($this);
        expect($second)->not->toBe($first);
        $this->getJson("/api/v1/public/loan-review/{$first}")->assertNotFound();

        LoanParty::where('role', 'borrower')->first()->forceFill(['review_expires_at' => now()->subMinute()])->save();
        $this->getJson("/api/v1/public/loan-review/{$second}")->assertOk()->assertJsonPath('data.status', 'expired');
        $this->postJson("/api/v1/public/loan-review/{$second}/request-otp")->assertStatus(410)->assertJsonPath('code', 'REVIEW_LINK_EXPIRED');
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

        $this->actingAs($this->officer, 'api')->getJson("/api/v1/loans/{$this->loanId}/review/corrections")
            ->assertOk()->assertJsonPath('data.0.field_name', 'occupation')->assertJsonPath('data.0.proposed_value', 'Shop owner')->assertJsonPath('data.0.status', 'pending_review');

        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$this->loanId}/review/corrections/{$id}/resolve", ['outcome' => 'accepted'])->assertStatus(422);
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/loans/{$this->loanId}/review/corrections/{$id}/resolve", ['outcome' => 'accepted', 'remarks' => 'Updated the occupation'])
            ->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.resolution_remarks', 'Updated the occupation');

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
    it('can be required by a setting', function () {
        $required = fn () => Setting::where('key', 'loan_review.required_before_disbursement')->firstOrFail()->forceFill(['value' => '1'])->save();

        try {
            LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, null, null, $required);
            $this->fail('The loan was disbursed without the customer review.');
        } catch (BusinessRuleException $e) {
            expect($e->errorCode)->toBe('LOAN_NOT_READY')
                ->and(collect($e->context['problems'])->pluck('code')->all())->toBe(['REVIEW_NOT_CONFIRMED']);
        }
    });
});

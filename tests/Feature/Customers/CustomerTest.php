<?php

declare(strict_types=1);

use App\Enums\KycStatus;
use App\Models\Customer;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->branchId = Fixtures::branch('Colombo');
});

/**
 * A valid customer. 853400937V is a man born on 5 December 1985.
 */
function customerPayload(int $branchId, array $overrides = []): array
{
    return array_merge([
        'branch_id' => $branchId,
        'id_type' => 'nic',
        'id_number' => '853400937V',
        'full_name' => 'Kamal Perera',
        'name_with_initials' => 'K. Perera',
        'date_of_birth' => '1985-12-05',
        'phone_primary' => '077 123 4567',
        'address_line_1' => '12 Temple Road',
        'city' => 'Maharagama',
        'gn_division' => 'Maharagama West',
        'ds_division' => 'Maharagama',
        'district' => 'Colombo',
        'province' => 'Western',
        'employment_status' => 'employed',
        'occupation' => 'Teacher',
        'employer_name' => 'Ministry of Education',
    ], $overrides);
}

describe('registration', function () {
    it('normalises the NIC and phone and masks them for normal users', function () {
        $user = Fixtures::staffInBranch($this->branchId, 'Customer Create');

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/v1/customers', customerPayload($this->branchId))
            ->assertCreated()
            ->assertJsonPath('data.identity.id_number', '1985******37')
            ->assertJsonPath('data.identity.gender', 'male')
            ->assertJsonPath('data.identity.date_of_birth', null)
            ->assertJsonPath('data.contact.phone_primary', '+9477****567')
            ->assertJsonPath('data.kyc.status', 'pending');

        $customer = Customer::first();

        expect($customer->normalizedIdNumber())->toBe('198534000937')
            ->and($customer->phone_primary)->toBe('+94771234567')
            ->and($customer->customer_no)->toBe('CUS-'.str_pad((string) $customer->id, 6, '0', STR_PAD_LEFT))
            ->and($response->json('data'))->not->toHaveKey('id');
    });

    it('shows full details to users with the sensitive permission', function () {
        $user = Fixtures::staffInBranch($this->branchId, 'Customer Create', 'Customer View Sensitive');

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/customers', customerPayload($this->branchId))
            ->assertCreated()
            ->assertJsonPath('data.identity.id_number', '853400937V')
            ->assertJsonPath('data.contact.phone_primary', '+94771234567');
    });

    it('refuses the same person registered again with the new NIC format', function () {
        $user = Fixtures::staffInBranch($this->branchId, 'Customer Create');
        $this->actingAs($user, 'api')->postJson('/api/v1/customers', customerPayload($this->branchId))->assertCreated();

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/customers', customerPayload($this->branchId, ['id_number' => '198534000937', 'phone_primary' => '0719876543']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'CUSTOMER_ALREADY_EXISTS');
    });

    it('cross-checks the date of birth with the NIC', function () {
        $this->actingAs(Fixtures::staffInBranch($this->branchId, 'Customer Create'), 'api')
            ->postJson('/api/v1/customers', customerPayload($this->branchId, ['date_of_birth' => '1985-12-06']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'NIC_DOB_MISMATCH');
    });

    it('refuses customers under 18', function () {
        $this->actingAs(Fixtures::staffInBranch($this->branchId, 'Customer Create'), 'api')
            ->postJson('/api/v1/customers', customerPayload($this->branchId, [
                'id_type' => 'passport', 'id_number' => 'N1234567', 'date_of_birth' => now()->subYears(17)->toDateString(),
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_UNDER_AGE');
    });

    it('rejects invalid identity and phone formats', function () {
        $this->actingAs(Fixtures::staffInBranch($this->branchId, 'Customer Create'), 'api')
            ->postJson('/api/v1/customers', customerPayload($this->branchId, ['id_number' => '853700937V', 'phone_primary' => '0112345678', 'district' => 'Atlantis']))
            ->assertStatus(422)
            ->assertJsonFragment(['field' => 'id_number'])
            ->assertJsonFragment(['field' => 'phone_primary'])
            ->assertJsonFragment(['field' => 'district']);
    });

    it('keeps employer and business contacts for verification, masked like the customer\'s own', function () {
        $user = Fixtures::staffInBranch($this->branchId, 'Customer Create');

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/customers', customerPayload($this->branchId, [
                'landmark' => 'Opposite the Bodhi tree',
                'employer_phone' => '011 234 5678',
                'employer_email' => 'hr@moe.gov.lk',
                'business_nature' => 'Tuition classes',
                'business_address' => '4 School Lane, Maharagama',
                'business_phone' => '0719876543',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.address.landmark', 'Opposite the Bodhi tree')
            ->assertJsonPath('data.employment.business_nature', 'Tuition classes')
            ->assertJsonPath('data.employment.employer_phone', '+9411****678')
            ->assertJsonPath('data.employment.employer_email', 'hr*@moe.gov.lk');

        expect(Customer::first()->employer_phone)->toBe('+94112345678')
            ->and(Customer::first()->business_phone)->toBe('+94719876543');
    });

    it('says where a person is registered when it is another branch, without a link to the record', function () {
        $kandy = Fixtures::branch('Kandy');
        $kandyOfficer = Fixtures::staffInBranch($kandy, 'Customer Create');
        $this->actingAs($kandyOfficer, 'api')->postJson('/api/v1/customers', customerPayload($kandy))->assertCreated();

        $this->actingAs(Fixtures::staffInBranch($this->branchId, 'Customer Index'), 'api')
            ->getJson('/api/v1/customers/lookup?id_type=nic&id_number=853400937V')
            ->assertOk()
            ->assertJsonPath('data.exists', true)
            ->assertJsonPath('data.customer.branch', 'Kandy')
            ->assertJsonPath('data.customer.accessible', false)
            ->assertJsonPath('data.customer.public_id', null);
    });

    it('ranks customers by credit score for the credit scores screen', function () {
        $user = Fixtures::staffInBranch($this->branchId, 'Customer Create', 'Customer Index');
        $this->actingAs($user, 'api')->postJson('/api/v1/customers', customerPayload($this->branchId))->assertCreated();
        $this->actingAs($user, 'api')->postJson('/api/v1/customers', customerPayload($this->branchId, ['id_number' => '199012345678', 'date_of_birth' => '1990-05-02', 'phone_primary' => '0771111111']))->assertCreated();
        Customer::where('id_number_hash', Customer::idNumberHash('198534000937'))->update(['credit_score' => 720]);
        Customer::where('id_number_hash', Customer::idNumberHash('199012345678'))->update(['credit_score' => 480]);

        $this->actingAs($user, 'api')->getJson('/api/v1/customers?sort=-credit_score')
            ->assertOk()
            ->assertJsonPath('data.items.0.credit_score', 720)
            ->assertJsonPath('data.items.1.credit_score', 480);
    });

    it('finds a registered person by either NIC format', function () {
        $user = Fixtures::staffInBranch($this->branchId, 'Customer Create', 'Customer Index');
        $this->actingAs($user, 'api')->postJson('/api/v1/customers', customerPayload($this->branchId))->assertCreated();

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/customers/lookup?id_type=nic&id_number=198534000937')
            ->assertOk()
            ->assertJsonPath('data.exists', true);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/customers?search=853400937V')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    });
});

describe('branch scoping', function () {
    it('hides other branches and refuses registering into them', function () {
        $kandy = Fixtures::branch('Kandy');
        $colomboOfficer = Fixtures::staffInBranch($this->branchId, 'Customer Create', 'Customer Index');
        $kandyOfficer = Fixtures::staffInBranch($kandy, 'Customer Create', 'Customer Index', 'Customer Update');

        $publicId = $this->actingAs($colomboOfficer, 'api')
            ->postJson('/api/v1/customers', customerPayload($this->branchId))
            ->assertCreated()
            ->json('data.public_id');

        // The Kandy officer neither lists nor opens nor edits the Colombo customer.
        $this->actingAs($kandyOfficer, 'api')->getJson('/api/v1/customers')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->actingAs($kandyOfficer, 'api')->getJson("/api/v1/customers/{$publicId}")->assertNotFound();
        $this->actingAs($kandyOfficer, 'api')->putJson("/api/v1/customers/{$publicId}", ['city' => 'Kandy'])->assertNotFound();

        $this->actingAs($kandyOfficer, 'api')
            ->postJson('/api/v1/customers', customerPayload($this->branchId, ['id_number' => '901234567V', 'date_of_birth' => '1990-05-02']))
            ->assertStatus(403)
            ->assertJsonPath('code', 'BRANCH_NOT_ALLOWED');
    });

    it('lets head office see every branch', function () {
        $colomboOfficer = Fixtures::staffInBranch($this->branchId, 'Customer Create');
        $headOffice = Fixtures::staffInBranch(Fixtures::branch('Head Office'), 'Customer Index', 'Branch Data All');

        $this->actingAs($colomboOfficer, 'api')->postJson('/api/v1/customers', customerPayload($this->branchId))->assertCreated();

        $this->actingAs($headOffice, 'api')->getJson('/api/v1/customers')->assertOk()->assertJsonPath('data.meta.total', 1);
    });
});

describe('kyc', function () {
    beforeEach(function () {
        $this->sms = Fixtures::fakeSms();
        $this->officer = Fixtures::staffInBranch($this->branchId, 'Customer Create', 'Customer Update', 'Customer KYC Verify');
        $this->publicId = $this->actingAs($this->officer, 'api')
            ->postJson('/api/v1/customers', customerPayload($this->branchId))
            ->json('data.public_id');
    });

    it('confirms details with the code sent to the customer, never returning the code', function () {
        $response = $this->actingAs($this->officer, 'api')
            ->postJson("/api/v1/customers/{$this->publicId}/kyc/send-otp")
            ->assertOk()
            ->assertJsonPath('data.sent_to', '+9477****567');

        $code = $this->sms->lastCode();

        expect($code)->not->toBeNull()
            ->and($response->getContent())->not->toContain($code)
            ->and($this->sms->sent[0]['to'])->toBe('+94771234567');

        $this->actingAs($this->officer, 'api')
            ->postJson("/api/v1/customers/{$this->publicId}/kyc/verify", ['code' => $code === '000000' ? '111111' : '000000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_INVALID');

        $this->actingAs($this->officer, 'api')
            ->postJson("/api/v1/customers/{$this->publicId}/kyc/verify", ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.kyc.status', 'verified');

        // A used code cannot be used again.
        $this->actingAs($this->officer, 'api')
            ->postJson("/api/v1/customers/{$this->publicId}/kyc/verify", ['code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('code', 'KYC_OTP_NOT_REQUESTED');
    });

    it('goes back to pending when confirmed details change', function () {
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/customers/{$this->publicId}/kyc/send-otp");
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/customers/{$this->publicId}/kyc/verify", ['code' => $this->sms->lastCode()])->assertOk();

        // A change outside the KYC fields keeps the confirmation.
        $this->actingAs($this->officer, 'api')->putJson("/api/v1/customers/{$this->publicId}", ['email' => 'kamal@example.com'])->assertOk()
            ->assertJsonPath('data.kyc.status', 'verified');

        $this->actingAs($this->officer, 'api')->putJson("/api/v1/customers/{$this->publicId}", ['address_line_1' => '99 New Road'])->assertOk()
            ->assertJsonPath('data.kyc.status', 'pending');

        expect(Customer::where('public_id', $this->publicId)->first()->kyc_status)->toBe(KycStatus::Pending);
    });

    it('refuses a code once the details changed after it was sent', function () {
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/customers/{$this->publicId}/kyc/send-otp");
        $code = $this->sms->lastCode();

        $this->actingAs($this->officer, 'api')->putJson("/api/v1/customers/{$this->publicId}", ['phone_primary' => '0719876543'])->assertOk();

        $this->actingAs($this->officer, 'api')
            ->postJson("/api/v1/customers/{$this->publicId}/kyc/verify", ['code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_PAYLOAD_CHANGED');
    });

    it('locks after too many wrong codes, even across resends', function () {
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/customers/{$this->publicId}/kyc/send-otp");
        $wrong = $this->sms->lastCode() === '000000' ? '111111' : '000000';

        foreach (range(1, 4) as $attempt) {
            $this->actingAs($this->officer, 'api')->postJson("/api/v1/customers/{$this->publicId}/kyc/verify", ['code' => $wrong])->assertJsonPath('code', 'OTP_INVALID');

            if ($attempt === 2) {
                $this->actingAs($this->officer, 'api')->postJson("/api/v1/customers/{$this->publicId}/kyc/resend-otp")->assertOk();
                $wrong = $this->sms->lastCode() === '000000' ? '111111' : '000000';
            }
        }

        $this->actingAs($this->officer, 'api')
            ->postJson("/api/v1/customers/{$this->publicId}/kyc/verify", ['code' => $wrong])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_LOCKED');
    });
});

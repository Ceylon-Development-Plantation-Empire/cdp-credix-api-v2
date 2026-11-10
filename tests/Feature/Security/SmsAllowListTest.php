<?php

declare(strict_types=1);

use App\Services\Sms\AllowListedSmsService;
use App\Services\SmsService;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Tests\Support\FakeSms;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

/**
 * A fake gateway registered like the real one (built by the container), so the same wrapping
 * the application gets applies to it.
 */
function gateway(): FakeSms
{
    $fake = Fixtures::fakeSms();
    app()->bind(SmsService::class, fn () => $fake);

    return $fake;
}

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
});

it('sends every SMS to the allow-listed handsets instead of the customer while testing', function () {
    config(['services.dialog_sms.allowed_numbers' => ['0711111111']]);
    $sms = gateway();
    $customer = LoanFixtures::customer($this->branchId, ['phone_primary' => '+94779998887']);
    $officer = Fixtures::staffInBranch($this->branchId, 'Customer KYC Verify', 'Customer Index');

    expect(app(SmsService::class))->toBeInstanceOf(AllowListedSmsService::class);

    $this->actingAs($officer, 'api')->postJson("/api/v1/customers/{$customer->public_id}/kyc/send-otp")->assertOk();

    // The customer's number is never texted; the tester gets the same text, saying who it was for.
    expect($sms->sent)->toHaveCount(1)
        ->and($sms->sent[0]['to'])->toBe('+94711111111')
        ->and($sms->sent[0]['message'])->toStartWith('[TEST for ')
        ->and($sms->sent[0]['message'])->not->toContain('779998887')
        ->and($sms->lastCode())->toMatch('/^\d{6}$/');
});

it('sends to an allow-listed number directly, unchanged', function () {
    config(['services.dialog_sms.allowed_numbers' => ['0711111111', '0722222222']]);
    $sms = gateway();

    app(SmsService::class)->sendSms('0722222222', 'Hello');

    expect($sms->sent)->toBe([['to' => '0722222222', 'message' => 'Hello']]);
});

it('never applies the allow-list in production', function () {
    config(['services.dialog_sms.allowed_numbers' => ['0711111111']]);
    app()->detectEnvironment(fn () => 'production');
    $sms = gateway();

    expect(app(SmsService::class))->toBe($sms);
});

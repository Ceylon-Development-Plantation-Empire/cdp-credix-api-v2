<?php

declare(strict_types=1);

use Tests\Support\Fixtures;

/*
| GET /enums: the fixed option lists screens build their dropdowns from.
*/

it('lists the fixed options, and what each charge behaviour allows, for any signed-in user', function () {
    $user = Fixtures::staffInBranch(Fixtures::branch());

    $response = $this->actingAs($user, 'api')->getJson('/api/v1/enums')->assertOk();

    expect(collect($response->json('data.payment_channel'))->pluck('value')->all())->toContain('cash', 'bank_transfer', 'cheque');
    expect(collect($response->json('data.loan_status'))->pluck('value')->all())->toContain('in_review', 'verified', 'approved', 'active');
    expect(collect($response->json('data.document_format'))->pluck('value')->all())->toBe(['pdf', 'jpg', 'jpeg', 'png']);
    expect($response->json('data.loan_status.0'))->toHaveKeys(['value', 'label']);

    $upfront = collect($response->json('data.charge_behaviour'))->firstWhere('value', 'upfront');
    expect($upfront['event_driven'])->toBeFalse()
        ->and($upfront['calculations'])->toContain('fixed', 'percent_of_principal')
        ->and($upfront['collections'])->toContain('deduct_from_disbursement');

    $event = collect($response->json('data.notification_event'))->firstWhere('value', 'loan.approved');
    expect($event['placeholders'])->toBeArray()->and($event['label'])->toBe('Loan Approved');

    $late = collect($response->json('data.charge_behaviour'))->firstWhere('value', 'late_payment');
    expect($late['event_driven'])->toBeTrue()->and($late['collections'])->toBe(['on_event']);
});

it('needs a signed-in user', function () {
    $this->getJson('/api/v1/enums')->assertUnauthorized();
});

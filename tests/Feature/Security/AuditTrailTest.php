<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

/*
| The audit trail (module G): who changed what, from which value to which, with personal data
| masked, never editable.
*/

it('records the old and new values of a change, masking personal data and never showing secrets', function () {
    $branchId = Fixtures::branch();
    $customer = LoanFixtures::customer($branchId, ['id_number' => '853400937V', 'date_of_birth' => '1985-12-05'], withDocuments: false);
    $officer = Fixtures::staffInBranch($branchId, 'Customer Update');

    $this->actingAs($officer, 'api')->putJson("/api/v1/customers/{$customer->public_id}", [
        'occupation' => 'Principal',
        'phone_primary' => '0719876543',
    ])->assertOk();

    // The field-level entry (the controller also writes a short business entry).
    $entry = ActivityLog::query()->where('module', 'Customer')->where('action', 'UPDATE')->latest('id')->get()
        ->first(fn (ActivityLog $log) => isset($log->payload['subject']));

    expect($entry->user_id)->toBe($officer->id)
        ->and($entry->payload['subject'])->toBe(['type' => 'customer', 'id' => $customer->id])
        ->and($entry->payload['old']['occupation'])->toBe('Teacher')
        ->and($entry->payload['new']['occupation'])->toBe('Principal')
        // SECURITY: a phone number is shown with its last four digits only.
        ->and($entry->payload['new']['phone_primary'])->toBe('********6543')
        ->and($entry->ip_address)->toBe('127.0.0.1');
});

it('marks an encrypted value as changed without writing it, and never records a password', function () {
    $user = staffWith();
    $user->forceFill(['password' => 'Another-Secret-9!'])->save();

    // The password change is on record as a change, without any value (neither text nor hash).
    $entry = ActivityLog::query()->where('module', 'User')->where('action', 'UPDATE')->latest('id')->firstOrFail();
    expect($entry->payload['new']['password'])->toBe('[REDACTED]')
        ->and(json_encode($entry->payload))->not->toContain('Another-Secret')
        ->and(json_encode($entry->payload))->not->toContain('$2y$');

    $customer = LoanFixtures::customer(Fixtures::branch(), withDocuments: false);
    $created = ActivityLog::query()->where('module', 'Customer')->where('action', 'CREATE')->latest('id')->firstOrFail();

    expect($created->payload['new']['id_number'])->toBe('[encrypted]')
        ->and(json_encode($created->payload))->not->toContain($customer->id_number)
        ->and($created->payload['new'])->not->toHaveKey('id_number_hash');
});

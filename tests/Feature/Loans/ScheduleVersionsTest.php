<?php

declare(strict_types=1);

use App\Models\ApprovalRequest;
use App\Models\LoanInstallment;
use App\Support\BusinessDate;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);

    $this->cashier = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Restructure Request', 'Approval Request Index');
    $this->supervisor = Fixtures::staffInBranch($this->branchId, 'Approval Request Index', 'Loan Restructure Approve');
    $this->loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: BusinessDate::todayImmutable()->subDays(40));
});

it('shows one version after disbursement and previews a restructure without saving it', function () {
    $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$this->loan->public_id}/schedule-versions")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.version', 1)
        ->assertJsonPath('data.0.source', 'disbursement')
        ->assertJsonPath('data.0.is_current', true)
        ->assertJsonPath('data.0.installments', 12);

    $preview = $this->actingAs($this->cashier, 'api')->postJson("/api/v1/loans/{$this->loan->public_id}/restructure/preview", ['tenure' => 24, 'rate' => '16', 'remarks' => 'What if'])
        ->assertOk()->json('data');

    expect($preview['schedule'])->toHaveCount(24)
        ->and($preview['to']['tenure'])->toBe(24)
        ->and($preview['to']['schedule_version'])->toBe(2);

    // Nothing was saved: no approval request, no new installments.
    expect(ApprovalRequest::where('action', 'restructure')->count())->toBe(0)
        ->and(LoanInstallment::where('loan_id', $this->loan->id)->max('schedule_version'))->toBe(1);
});

it('adds a version after an approved restructure, with who, why and what changed', function () {
    $approval = $this->actingAs($this->cashier, 'api')->postJson("/api/v1/loans/{$this->loan->public_id}/restructure", ['tenure' => 24, 'rate' => '16', 'remarks' => 'Income dropped after flood'])
        ->assertStatus(202)->json('data.public_id');

    $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$this->loan->public_id}/restructures")
        ->assertOk()->assertJsonPath('data.0.status', 'pending')->assertJsonPath('data.0.payload.tenure', 24);

    $this->actingAs($this->supervisor, 'api')->postJson("/api/v1/approval-requests/{$approval}/approve", ['remarks' => 'Checked'])->assertOk();

    $versions = $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$this->loan->public_id}/schedule-versions")
        ->assertOk()->assertJsonCount(2, 'data')->json('data');

    expect($versions[0]['is_current'])->toBeFalse()
        ->and($versions[0]['status_counts']['rescheduled'])->toBe(12)
        ->and($versions[1]['version'])->toBe(2)
        ->and($versions[1]['is_current'])->toBeTrue()
        ->and($versions[1]['source'])->toBe('restructure')
        ->and($versions[1]['installments'])->toBe(24)
        ->and($versions[1]['reason'])->toContain('Income dropped after flood')
        ->and($versions[1]['terms'])->toBe(['tenure' => 24, 'rate' => '16.0000'])
        ->and($versions[1]['created_by'])->not->toBeNull();

    $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$this->loan->public_id}/restructures")
        ->assertOk()->assertJsonPath('data.0.status', 'approved')->assertJsonPath('data.0.decided_by.name', $this->supervisor->name);

    // The schedule can be read one revision at a time.
    $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$this->loan->public_id}/schedule?version=1")->assertOk()->assertJsonCount(12, 'data.schedule');
    $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$this->loan->public_id}/schedule?version=current")->assertOk()->assertJsonCount(24, 'data.schedule');
    $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$this->loan->public_id}/schedule")->assertOk()->assertJsonCount(24, 'data.schedule');
    $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$this->loan->public_id}/schedule?version=all")->assertOk()->assertJsonCount(36, 'data.schedule');
    $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$this->loan->public_id}/schedule?version=abc")->assertStatus(422);
});

it('needs the permissions', function () {
    $viewer = Fixtures::staffInBranch($this->branchId, 'Loan Index');

    $this->actingAs($viewer, 'api')->postJson("/api/v1/loans/{$this->loan->public_id}/restructure/preview", ['tenure' => 24, 'remarks' => 'x'])->assertStatus(403);
    $this->actingAs(Fixtures::staffInBranch($this->branchId, 'Payment Index'), 'api')->getJson("/api/v1/loans/{$this->loan->public_id}/schedule-versions")->assertStatus(403);
});

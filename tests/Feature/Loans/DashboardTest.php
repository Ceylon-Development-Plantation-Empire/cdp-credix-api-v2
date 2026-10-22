<?php

declare(strict_types=1);

use App\Models\Setting;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\SettingsSeeder;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

/*
| GET /dashboard/overview: the figures a lender watches, for a period, against the period before,
| limited to the user's branch.
*/

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class, SettingsSeeder::class]);
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);
    $this->viewer = Fixtures::staffInBranch($this->branchId, 'Dashboard View');
});

it('answers on an empty system with zeros, not errors', function () {
    $this->actingAs($this->viewer, 'api')->getJson('/api/v1/dashboard/overview')->assertOk()
        ->assertJsonPath('data.active_loans', 0)
        ->assertJsonPath('data.kpis.disbursed.value', '0.00')
        ->assertJsonPath('data.kpis.disbursed.change_percent', null)
        ->assertJsonPath('data.kpis.approval_rate_percent', null)
        ->assertJsonPath('data.kpis.collection_rate_percent', null)
        ->assertJsonPath('data.targets.disbursement.target', null)
        ->assertJsonCount(6, 'data.pipeline')
        ->assertJsonCount(6, 'data.arrears_aging')
        ->assertJsonCount(6, 'data.trend');
});

it('adds up the portfolio, the pipeline and the period against the one before', function () {
    $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);
    LoanFixtures::approvedLoan($this->branchId, 'sme-loan', '80000', 6);

    $data = $this->actingAs($this->viewer, 'api')->getJson('/api/v1/dashboard/overview')->assertOk()->json('data');

    expect($data['active_loans'])->toBe(1)
        ->and($data['kpis']['disbursed']['value'])->toBe('120000.00')
        ->and($data['kpis']['disbursed']['loans'])->toBe(1)
        ->and($data['kpis']['disbursed']['previous'])->toBe('0.00')
        ->and($data['kpis']['outstanding_principal'])->toBe($data['outstanding_principal'])
        ->and($data['kpis']['par30_percent'])->toBe('0.00')
        ->and($data['period']['to'])->toBe(now()->toDateString())
        ->and(collect($data['pipeline'])->firstWhere('status', 'approved'))->toBe(['status' => 'approved', 'applications' => 1, 'amount' => '80000.00'])
        ->and($data['portfolio_by_product'][0]['product']['slug'])->toBe('sme-loan')
        ->and($data['portfolio_by_product'][0]['share_percent'])->toBe('100.00')
        ->and($data['recent_applications'])->toHaveCount(2)
        ->and($data['recent_transactions'])->toBe([]);

    expect($loan->refresh()->status->value)->toBe('active');
});

it('takes the period and the targets from the request and the settings', function () {
    LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);
    Setting::where('key', 'dashboard.target.disbursement')->firstOrFail()->forceFill(['value' => '240000'])->save();

    $this->actingAs($this->viewer, 'api')->getJson('/api/v1/dashboard/overview')->assertOk()
        ->assertJsonPath('data.targets.disbursement.target', '240000.00')
        ->assertJsonPath('data.targets.disbursement.percentage', '50.00');

    // A period in the past holds nothing; the days before it do not either.
    $this->actingAs($this->viewer, 'api')->getJson('/api/v1/dashboard/overview?from=2020-01-01&to=2020-01-31')->assertOk()
        ->assertJsonPath('data.kpis.disbursed.value', '0.00')
        ->assertJsonPath('data.period.days', 31);

    $this->actingAs($this->viewer, 'api')->getJson('/api/v1/dashboard/overview?from=2025-01-01&to=2026-12-31')->assertStatus(422);
    $this->actingAs($this->viewer, 'api')->getJson('/api/v1/dashboard/overview?from=2020-02-01&to=2020-01-01')->assertStatus(422);
});

it('shows a branch user only their branch', function () {
    LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);
    $kandy = Fixtures::staffInBranch(Fixtures::branch('Kandy'), 'Dashboard View');

    $this->actingAs($kandy, 'api')->getJson('/api/v1/dashboard/overview')->assertOk()
        ->assertJsonPath('data.active_loans', 0)
        ->assertJsonPath('data.kpis.disbursed.value', '0.00')
        ->assertJsonPath('data.branches', []);
});

it('needs the dashboard permission', function () {
    $nobody = Fixtures::staffInBranch($this->branchId, 'Loan Index');

    $this->actingAs($nobody, 'api')->getJson('/api/v1/dashboard/overview')->assertForbidden();
});

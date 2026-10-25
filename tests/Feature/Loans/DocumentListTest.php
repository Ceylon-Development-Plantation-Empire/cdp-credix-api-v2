<?php

declare(strict_types=1);

use App\Models\Loan;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

/*
| GET /documents : the documents of every application before approval (submitted, in review, verified),
| whoever owns them (the loan, its customers, its collateral), filtered and shown by permission.
*/

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);
    $this->officer = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Create', 'Document Index', 'Document Update', 'Document Delete');
    $this->viewer = Fixtures::staffInBranch($this->branchId, 'Document Index');
});

function loanAt($test, string $status, $customer = null): string
{
    $customer ??= LoanFixtures::customer($test->branchId);
    $id = $test->actingAs($test->officer, 'api')->postJson('/api/v1/loans', [
        'product_slug' => 'sme-loan', 'borrower_type' => 'individual', 'customer_public_id' => $customer->public_id,
        'requested_amount' => '100000', 'tenure' => 12,
    ])->assertCreated()->json('data.public_id');

    Loan::where('public_id', $id)->update(['status' => $status]);

    return $id;
}

it('lists the documents of applications in review and verified, and leaves out drafts and approved ones', function () {
    $review = loanAt($this, 'in_review');
    $verified = loanAt($this, 'verified');
    loanAt($this, 'draft');
    loanAt($this, 'approved');

    $response = $this->actingAs($this->viewer, 'api')->getJson('/api/v1/documents?per_page=100')
        ->assertOk()->assertJsonPath('data.meta.total', 16);

    $loans = collect($response->json('data.items'))->flatMap(fn ($d) => collect($d['loans'])->pluck('public_id'))->unique()->sort()->values()->all();
    expect($loans)->toBe(collect([$review, $verified])->sort()->values()->all());
    expect($response->json('data.items.0'))->toHaveKeys(['public_id', 'document_type', 'status', 'owner', 'loans', 'can']);
});

it('includes submitted applications and both the loan\'s and the customers\' documents', function () {
    $id = loanAt($this, 'submitted');
    $loan = Loan::where('public_id', $id)->first();
    LoanFixtures::verifiedDocument($loan, 'loan-agreement');

    $items = $this->actingAs($this->viewer, 'api')->getJson('/api/v1/documents?per_page=100')->assertOk()->json('data.items');

    expect(collect($items)->pluck('owner.type')->unique()->sort()->values()->all())->toBe(['customer', 'loan']);

    $this->actingAs($this->viewer, 'api')->getJson('/api/v1/documents?owner_type=loan')
        ->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.items.0.document_type.slug', 'loan-agreement')
        ->assertJsonPath('data.items.0.owner.name', $loan->application_no);
});

it('filters by status, document type, loan and search text', function () {
    $first = loanAt($this, 'in_review');
    loanAt($this, 'in_review');

    $this->actingAs($this->viewer, 'api')->getJson("/api/v1/documents?loan_public_id={$first}&document_type_slug=salary-slip")
        ->assertOk()->assertJsonPath('data.meta.total', 3);

    $this->actingAs($this->viewer, 'api')->getJson('/api/v1/documents?status=pending')->assertOk()->assertJsonPath('data.meta.total', 0);
    $this->actingAs($this->viewer, 'api')->getJson('/api/v1/documents?status=verified')->assertOk()->assertJsonPath('data.meta.total', 16);
    $this->actingAs($this->viewer, 'api')->getJson('/api/v1/documents?search=nic-copy')->assertOk()->assertJsonPath('data.meta.total', 2);
});

it('can be pointed at other stages', function () {
    loanAt($this, 'approved');
    loanAt($this, 'in_review');

    $this->actingAs($this->viewer, 'api')->getJson('/api/v1/documents?loan_status=approved')->assertOk()->assertJsonPath('data.meta.total', 8);
    $this->actingAs($this->viewer, 'api')->getJson('/api/v1/documents?loan_status=nonsense')->assertStatus(422);
});

it('shows what the user may do with each document, by permission', function () {
    loanAt($this, 'in_review');

    $this->actingAs($this->viewer, 'api')->getJson('/api/v1/documents')->assertOk()
        ->assertJsonPath('data.items.0.can', ['update' => false, 'verify' => false, 'delete' => false]);

    $this->actingAs($this->officer, 'api')->getJson('/api/v1/documents')->assertOk()
        ->assertJsonPath('data.items.0.can', ['update' => true, 'verify' => false, 'delete' => true]);
});

it('needs the document permission and stays inside the user\'s branch', function () {
    loanAt($this, 'in_review');

    $noPermission = Fixtures::staffInBranch($this->branchId, 'Loan Index');
    $this->actingAs($noPermission, 'api')->getJson('/api/v1/documents')->assertForbidden();

    $otherBranch = Fixtures::staffInBranch(Fixtures::branch('Kandy'), 'Document Index');
    $this->actingAs($otherBranch, 'api')->getJson('/api/v1/documents')->assertOk()->assertJsonPath('data.meta.total', 0);
});

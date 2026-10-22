<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Models\Product;
use App\Models\Template;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

/*
| Legal documents: the wording is a template (document channel), the document is what the legal
| officer prepared for a loan from it. Printed documents are evidence and stay.
*/

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);
    $this->legal = Fixtures::staffInBranch($this->branchId, 'Legal Document Index', 'Legal Document Manage', 'Legal Template Manage');
    $this->loan = LoanFixtures::approvedLoan($this->branchId, 'sme-loan', '150000', 12);
    $this->wording = fn (string $language = 'en', ?string $type = null) => $this->actingAs($this->legal, 'api')->postJson('/api/v1/legal-document-templates', [
        'document_type' => $type ?? 'direct_loan_agreement', 'language' => $language, 'content' => '<div class="legal-print-page">Agreement of {{customer_name}} ...</div>',
    ]);
});

function legalBody(string $loanId, array $extra = []): array
{
    return array_merge(['loan_public_id' => $loanId, 'document_type' => 'direct_loan_agreement', 'language' => 'en', 'details' => ['agreementDate' => '2026-10-22', 'witness1Name' => 'K. Perera']], $extra);
}

describe('wording', function () {
    it('keeps legal templates apart from the SMS templates', function () {
        ($this->wording)()->assertCreated()->assertJsonPath('data.document_type', 'direct_loan_agreement')->assertJsonPath('data.document_type_label', 'Direct Loan Agreement With Two Guarantors');

        expect(Template::where('channel', NotificationChannel::Document->value)->count())->toBe(1);

        ($this->wording)()->assertStatus(409);
        $this->actingAs($this->legal, 'api')->postJson('/api/v1/legal-document-templates', ['document_type' => 'nonsense', 'language' => 'en', 'content' => str_repeat('x', 30)])->assertStatus(422);
    });

    it('offers a product the most specific wording, one per type and language', function () {
        ($this->wording)()->assertCreated();
        ($this->wording)('si')->assertCreated();
        $this->actingAs($this->legal, 'api')->postJson('/api/v1/legal-document-templates', [
            'document_type' => 'direct_loan_agreement', 'language' => 'en', 'product_slug' => 'sme-loan', 'content' => '<div>SME wording that is long enough</div>',
        ])->assertCreated();

        $list = $this->actingAs($this->legal, 'api')->getJson('/api/v1/legal-document-templates/list?product_slug=sme-loan')->assertOk()->json('data');

        expect($list)->toHaveCount(2)
            ->and(collect($list)->firstWhere('language', 'en')['product']['slug'])->toBe('sme-loan');
    });
});

describe('documents', function () {
    it('saves a draft without wording, then prepares and prints it', function () {
        $draft = $this->actingAs($this->legal, 'api')->postJson('/api/v1/legal-documents', legalBody($this->loan->public_id))
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.reference_no', fn ($v) => str_starts_with($v, 'LEG-'));
        $id = $draft->json('data.public_id');

        // A prepared document needs approved wording in its language.
        $this->actingAs($this->legal, 'api')->putJson("/api/v1/legal-documents/{$id}", ['status' => 'created'])
            ->assertStatus(422)->assertJsonPath('code', 'LEGAL_TEMPLATE_MISSING');

        ($this->wording)()->assertCreated();

        $this->actingAs($this->legal, 'api')->patchJson("/api/v1/legal-documents/{$id}/record-print")->assertStatus(409)->assertJsonPath('code', 'LEGAL_DOCUMENT_NOT_READY');

        $this->actingAs($this->legal, 'api')->putJson("/api/v1/legal-documents/{$id}", ['status' => 'created', 'details' => ['agreementDate' => '2026-10-23']])
            ->assertOk()->assertJsonPath('data.status', 'created')->assertJsonPath('data.details.agreementDate', '2026-10-23')->assertJsonPath('data.prepared_at', fn ($v) => $v !== null);

        // The wording comes with a single document, not in the list.
        $this->actingAs($this->legal, 'api')->getJson("/api/v1/legal-documents/{$id}")->assertOk()->assertJsonPath('data.template.content', fn ($v) => str_contains($v, 'Agreement of'));
        $this->actingAs($this->legal, 'api')->getJson('/api/v1/legal-documents')->assertOk()->assertJsonMissingPath('data.items.0.template.content');

        $this->actingAs($this->legal, 'api')->patchJson("/api/v1/legal-documents/{$id}/record-print")->assertOk()->assertJsonPath('data.printed_count', 1);

        // Printed: kept as evidence.
        $this->actingAs($this->legal, 'api')->deleteJson("/api/v1/legal-documents/{$id}")->assertStatus(409)->assertJsonPath('code', 'LEGAL_DOCUMENT_PRINTED');
        $this->actingAs($this->legal, 'api')->putJson("/api/v1/legal-documents/{$id}", ['status' => 'pending'])->assertStatus(409);
    });

    it('never falls back to another language', function () {
        ($this->wording)('en')->assertCreated();

        $this->actingAs($this->legal, 'api')->postJson('/api/v1/legal-documents', legalBody($this->loan->public_id, ['language' => 'ta', 'status' => 'created']))
            ->assertStatus(422)->assertJsonPath('code', 'LEGAL_TEMPLATE_MISSING');
    });

    it('deletes an unprinted draft and refuses a second document of the same kind', function () {
        $id = $this->actingAs($this->legal, 'api')->postJson('/api/v1/legal-documents', legalBody($this->loan->public_id))->assertCreated()->json('data.public_id');

        $this->actingAs($this->legal, 'api')->postJson('/api/v1/legal-documents', legalBody($this->loan->public_id))->assertStatus(409)->assertJsonPath('code', 'LEGAL_DOCUMENT_EXISTS');

        $this->actingAs($this->legal, 'api')->deleteJson("/api/v1/legal-documents/{$id}")->assertOk();
        $this->actingAs($this->legal, 'api')->getJson("/api/v1/legal-documents/{$id}")->assertNotFound();
    });

    it('only prepares papers for approved, accepted or active loans', function () {
        $this->loan->forceFill(['status' => 'submitted'])->save();

        $this->actingAs($this->legal, 'api')->postJson('/api/v1/legal-documents', legalBody($this->loan->public_id))
            ->assertStatus(409)->assertJsonPath('code', 'LEGAL_LOAN_NOT_ELIGIBLE');
    });

    it('lists the loans waiting for their papers and the ones prepared', function () {
        $other = LoanFixtures::approvedLoan($this->branchId, 'sme-loan', '90000', 6);
        $this->actingAs($this->legal, 'api')->postJson('/api/v1/legal-documents', legalBody($this->loan->public_id))->assertCreated();

        $waiting = $this->actingAs($this->legal, 'api')->getJson('/api/v1/legal-documents/applications?legal_state=not_prepared')->assertOk()->json('data.items.*.public_id');
        $prepared = $this->actingAs($this->legal, 'api')->getJson('/api/v1/legal-documents/applications?legal_state=prepared')->assertOk()->json('data.items.*.public_id');

        expect($waiting)->toBe([$other->public_id])->and($prepared)->toBe([$this->loan->public_id]);
    });

    it('is closed to other branches and to users without the permission', function () {
        $id = $this->actingAs($this->legal, 'api')->postJson('/api/v1/legal-documents', legalBody($this->loan->public_id))->assertCreated()->json('data.public_id');

        $kandy = Fixtures::staffInBranch(Fixtures::branch('Kandy'), 'Legal Document Index', 'Legal Document Manage');
        $this->actingAs($kandy, 'api')->getJson("/api/v1/legal-documents/{$id}")->assertNotFound();
        $this->actingAs($kandy, 'api')->getJson('/api/v1/legal-documents')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->actingAs($kandy, 'api')->postJson('/api/v1/legal-documents', legalBody($this->loan->public_id))->assertNotFound();

        $viewer = Fixtures::staffInBranch($this->branchId, 'Legal Document Index');
        $this->actingAs($viewer, 'api')->getJson("/api/v1/legal-documents/{$id}")->assertOk();
        $this->actingAs($viewer, 'api')->deleteJson("/api/v1/legal-documents/{$id}")->assertForbidden();
        $this->actingAs($viewer, 'api')->postJson('/api/v1/legal-document-templates', ['document_type' => 'direct_loan_agreement', 'language' => 'en', 'content' => str_repeat('x', 30)])->assertForbidden();

        expect(Product::count())->toBeGreaterThan(0);
    });
});

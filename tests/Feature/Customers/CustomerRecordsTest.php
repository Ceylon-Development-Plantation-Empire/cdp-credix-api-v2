<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Document;
use Database\Seeders\DocumentRequirementSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed(DocumentRequirementSeeder::class);
    Storage::fake('private');

    $this->officer = Fixtures::staffInBranch($this->branchId, 'Customer Create', 'Customer Index', 'Document Upload', 'Document Index', 'Document Delete', 'Customer Bank Account Index', 'Customer Bank Account Create', 'Customer Bank Account Update', 'Customer Bank Account Delete', 'Customer Financial Index', 'Customer Financial Create', 'Customer Financial Update', 'Customer Financial Delete');
    $this->checker = Fixtures::staffInBranch($this->branchId, 'Document Verify', 'Document Index');

    $this->publicId = $this->actingAs($this->officer, 'api')
        ->postJson('/api/v1/customers', customerPayload($this->branchId))
        ->json('data.public_id');
});

function upload($test, string $publicId, string $type, string $name = 'scan.pdf', string $mime = 'application/pdf')
{
    return $test->actingAs($test->officer, 'api')->post("/api/v1/customers/{$publicId}/documents", [
        'document_type_slug' => $type,
        'file' => UploadedFile::fake()->create($name, 120, $mime),
        'issued_on' => now()->subDays(10)->toDateString(),
    ], ['Accept' => 'application/json']);
}

describe('documents', function () {
    it('stores uploads privately under a random name and never returns the path', function () {
        $response = upload($this, $this->publicId, 'nic-copy', '../../evil name.pdf')->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $document = Document::first();

        expect($response->json('data'))->not->toHaveKey('path')
            // Stored by the senior's FileUploadTrait (uploads/<module>/<random name>).
            ->and($document->path)->toStartWith('uploads/documents/')
            ->and($document->path)->not->toContain('evil')
            ->and($document->path)->toEndWith('.pdf');

        Storage::disk('private')->assertExists($document->path);
    });

    it('checks the real content type, not the file name', function () {
        upload($this, $this->publicId, 'nic-copy', 'shell.pdf', 'application/x-php')
            ->assertStatus(422)
            ->assertJsonPath('code', 'DOCUMENT_FORMAT_NOT_ALLOWED');

        // The other way round too: real PDF content under a script extension is never stored as .php.
        upload($this, $this->publicId, 'nic-copy', 'invoice.php', 'application/pdf')
            ->assertStatus(422)
            ->assertJsonPath('code', 'DOCUMENT_FORMAT_NOT_ALLOWED');
        expect(Document::count())->toBe(0);
    });

    it('needs the verify permission to verify a document', function () {
        $documentId = upload($this, $this->publicId, 'nic-copy')->json('data.public_id');

        // The officer who uploaded it has no verify permission.
        $this->actingAs($this->officer, 'api')->postJson("/api/v1/documents/{$documentId}/verify")->assertForbidden();

        $this->actingAs($this->checker, 'api')
            ->postJson("/api/v1/documents/{$documentId}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        // A verified document is evidence and cannot be deleted.
        $this->actingAs($this->officer, 'api')->deleteJson("/api/v1/documents/{$documentId}")->assertStatus(409);
    });

    it('builds the checklist from the document rules', function () {
        foreach (['nic-copy', 'gn-confirmation-letter'] as $type) {
            $id = upload($this, $this->publicId, $type)->json('data.public_id');
            $this->actingAs($this->checker, 'api')->postJson("/api/v1/documents/{$id}/verify")->assertOk();
        }

        $groups = collect($this->actingAs($this->officer, 'api')
            ->getJson("/api/v1/customers/{$this->publicId}/document-checklist")
            ->assertOk()
            ->assertJsonPath('data.complete', false)
            ->json('data.groups'))->keyBy('requirement_group');

        // NIC alone satisfies identity (any); the residency letters are optional, so they never hold the customer back;
        // the employed customer still needs the employment letter and the salary slips (one PDF), but not the
        // optional salary confirmation letter that sits in the same group.
        $employment = collect($groups['employment-proof']['documents'])->keyBy('document_type.slug');

        expect($groups['identity-proof']['satisfied'])->toBeTrue()
            ->and($groups['residency-proof']['is_mandatory'])->toBeFalse()
            ->and($groups['employment-proof']['satisfied'])->toBeFalse()
            ->and($employment['salary-slip']['min_count'])->toBe(1)
            ->and($employment['salary-slip']['is_mandatory'])->toBeTrue()
            ->and($employment['salary-confirmation-letter']['is_mandatory'])->toBeFalse()
            ->and($groups['other-documents']['is_mandatory'])->toBeFalse();
    });

    it('streams a download through the authorised endpoint', function () {
        $id = upload($this, $this->publicId, 'nic-copy')->json('data.public_id');

        $this->actingAs($this->officer, 'api')
            ->get("/api/v1/documents/{$id}/download")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    });
});

describe('bank accounts', function () {
    it('encrypts the account number and masks it in responses', function () {
        $this->actingAs($this->officer, 'api')
            ->postJson("/api/v1/customers/{$this->publicId}/bank-accounts", [
                'bank_name' => 'Bank of Ceylon',
                'account_holder_name' => 'K. Perera',
                'account_number' => '0081234567',
            ])
            ->assertCreated()
            ->assertJsonPath('data.account_number', '****4567')
            ->assertJsonPath('data.is_primary', true);

        $raw = DB::table('customer_bank_accounts')->value('account_number');
        expect($raw)->not->toContain('0081234567');

        $this->actingAs($this->officer, 'api')
            ->postJson("/api/v1/customers/{$this->publicId}/bank-accounts", [
                'bank_name' => 'Bank of Ceylon', 'account_holder_name' => 'K. Perera', 'account_number' => '0081234567',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'BANK_ACCOUNT_EXISTS');
    });
});

describe('financials', function () {
    it('needs the monthly installment for a liability and a category of the right kind', function () {
        $this->actingAs($this->officer, 'api')
            ->postJson("/api/v1/customers/{$this->publicId}/financials", ['kind' => 'liability', 'category' => 'salary', 'amount' => '500000'])
            ->assertStatus(422)
            ->assertJsonFragment(['field' => 'category'])
            ->assertJsonFragment(['field' => 'monthly_obligation']);

        $this->actingAs($this->officer, 'api')
            ->postJson("/api/v1/customers/{$this->publicId}/financials", [
                'kind' => 'liability', 'category' => 'bank_loan', 'institution_name' => 'HNB', 'amount' => '500000', 'monthly_obligation' => '25000',
            ])
            ->assertCreated()
            ->assertJsonPath('data.monthly_obligation', '25000.00');
    });
});

describe('borrower groups', function () {
    beforeEach(function () {
        $this->groupAdmin = Fixtures::staffInBranch($this->branchId, 'Borrower Group Create', 'Borrower Group Member Create', 'Borrower Group Member Update', 'Borrower Group Member Delete', 'Borrower Group Index');
        $this->groupA = $this->actingAs($this->groupAdmin, 'api')
            ->postJson('/api/v1/borrower-groups', ['name' => 'Sunrise Women', 'branch_id' => $this->branchId, 'formed_on' => '2026-01-10'])
            ->assertCreated()->json('data.public_id');
        $this->groupB = $this->actingAs($this->groupAdmin, 'api')
            ->postJson('/api/v1/borrower-groups', ['name' => 'Lotus Women', 'branch_id' => $this->branchId, 'formed_on' => '2026-02-10'])
            ->json('data.public_id');
    });

    it('allows a person in only one group at a time', function () {
        $memberId = $this->actingAs($this->groupAdmin, 'api')
            ->postJson("/api/v1/borrower-groups/{$this->groupA}/members", ['customer_public_id' => $this->publicId, 'role' => 'leader'])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->groupAdmin, 'api')
            ->postJson("/api/v1/borrower-groups/{$this->groupB}/members", ['customer_public_id' => $this->publicId])
            ->assertStatus(409)
            ->assertJsonPath('code', 'MEMBER_IN_ANOTHER_GROUP');

        // After leaving the first group the person can join another.
        $this->actingAs($this->groupAdmin, 'api')->postJson("/api/v1/borrower-groups/{$this->groupA}/members/{$memberId}/leave")->assertOk();

        $this->actingAs($this->groupAdmin, 'api')
            ->postJson("/api/v1/borrower-groups/{$this->groupB}/members", ['customer_public_id' => $this->publicId])
            ->assertCreated();
    });

    it('keeps one leader per group', function () {
        $second = $this->actingAs($this->officer, 'api')
            ->postJson('/api/v1/customers', customerPayload($this->branchId, ['id_number' => '901234567V', 'date_of_birth' => '1990-05-02', 'phone_primary' => '0719876543']))
            ->json('data.public_id');

        $this->actingAs($this->groupAdmin, 'api')->postJson("/api/v1/borrower-groups/{$this->groupA}/members", ['customer_public_id' => $this->publicId, 'role' => 'leader'])->assertCreated();

        $this->actingAs($this->groupAdmin, 'api')
            ->postJson("/api/v1/borrower-groups/{$this->groupA}/members", ['customer_public_id' => $second, 'role' => 'leader'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'GROUP_ROLE_TAKEN');
    });

    it('only takes members from the group branch', function () {
        $kandy = Fixtures::branch('Kandy');
        $kandyCustomer = Customer::first()->replicate(['customer_no', 'public_id']);
        $kandyCustomer->forceFill(['branch_id' => $kandy, 'id_number' => '199012300567', 'date_of_birth' => '1990-05-03'])->save();

        // A Colombo user cannot even see the Kandy customer…
        $this->actingAs($this->groupAdmin, 'api')
            ->postJson("/api/v1/borrower-groups/{$this->groupA}/members", ['customer_public_id' => $kandyCustomer->public_id])
            ->assertNotFound();

        // …and head office, who can see both, is still refused: groups only take members of their branch.
        $headOffice = Fixtures::staffInBranch($kandy, 'Borrower Group Member Create', 'Borrower Group Member Update', 'Borrower Group Member Delete', 'Branch Data All');

        $this->actingAs($headOffice, 'api')
            ->postJson("/api/v1/borrower-groups/{$this->groupA}/members", ['customer_public_id' => $kandyCustomer->public_id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'GROUP_BRANCH_MISMATCH');
    });
});

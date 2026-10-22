<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Models\Product;
use Database\Seeders\ProductCatalogSeeder;

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);
});

/**
 * A complete, valid conventional product payload; tests override what they need.
 */
function productPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Festival Advance',
        'category_slug' => 'standard-borrowing',
        'pricing_method' => 'flat',
        'default_rate' => '24',
        'min_rate' => '18',
        'max_rate' => '30',
        'min_amount' => '10000',
        'max_amount' => '500000',
        'min_tenure' => 3,
        'max_tenure' => 24,
        'repayment_frequency' => 'monthly',
        'charges' => [
            ['charge_type' => 'processing_fee', 'calculation' => 'percent_of_principal', 'value' => '1', 'min_amount' => '500', 'collection' => 'deduct_from_disbursement'],
            ['charge_type' => 'late_payment', 'calculation' => 'fixed', 'value' => '250', 'collection' => 'on_event', 'recurrence' => 'monthly_while_overdue'],
        ],
    ], $overrides);
}

describe('categories', function () {
    it('makes a sub-category inherit the basis of its parent', function () {
        $user = staffWith('Product Category Create');

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/product-categories', ['name' => 'Agri Fund', 'parent_slug' => 'islamic'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'agri-fund')
            ->assertJsonPath('data.finance_basis', 'islamic')
            ->assertJsonPath('data.parent.slug', 'islamic');
    });

    it('returns the tree with nested children', function () {
        $this->actingAs(staffWith('Product Category Index'), 'api')
            ->getJson('/api/v1/product-categories/tree')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'general')
            ->assertJsonPath('data.0.children.0.slug', 'standard-borrowing')
            ->assertJsonPath('data.1.children.1.slug', 'islamic-development-fund');
    });

    it('refuses to move a category under its own child', function () {
        $this->actingAs(staffWith('Product Category Update'), 'api')
            ->putJson('/api/v1/product-categories/general', ['parent_slug' => 'standard-borrowing'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CATEGORY_LOOP');
    });

    it('refuses to move a category into the other basis', function () {
        $this->actingAs(staffWith('Product Category Update'), 'api')
            ->putJson('/api/v1/product-categories/development-fund', ['parent_slug' => 'islamic'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CATEGORY_BASIS_MISMATCH');
    });
});

describe('product rules', function () {
    it('creates a draft product without leaking internal ids', function () {
        $response = $this->actingAs(staffWith('Product Create'), 'api')
            ->postJson('/api/v1/products', productPayload())
            ->assertCreated()
            ->assertJsonPath('data.slug', 'festival-advance')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.cost_label', 'Interest')
            ->assertJsonCount(2, 'data.charges');

        expect($response->json('data'))->not->toHaveKeys(['id', 'category_id', 'created_by']);
    });

    it('refuses interest pricing on an islamic product', function () {
        $this->actingAs(staffWith('Product Create'), 'api')
            ->postJson('/api/v1/products', productPayload(['category_slug' => 'islamic-standard-borrowing', 'pricing_method' => 'reducing_balance', 'charges' => []]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PRODUCT_INVALID_TERMS');
    });

    it('refuses a rate and percentage fees on qard hasan', function () {
        $response = $this->actingAs(staffWith('Product Create'), 'api')
            ->postJson('/api/v1/products', productPayload([
                'category_slug' => 'islamic-development-fund',
                'pricing_method' => 'qard_hasan',
                'charges' => [
                    ['charge_type' => 'processing_fee', 'calculation' => 'percent_of_principal', 'value' => '1', 'collection' => 'deduct_from_disbursement'],
                ],
            ]))
            ->assertStatus(422);

        expect(implode(' ', $response->json('details.errors')))
            ->toContain('all rates must be 0')
            ->toContain('must be a fixed actual-cost amount');
    });

    it('sends islamic late payment charges to charity', function () {
        $this->actingAs(staffWith('Product Create'), 'api')
            ->postJson('/api/v1/products', productPayload([
                'category_slug' => 'islamic-standard-borrowing',
                'pricing_method' => 'murabaha',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('details.errors.0', 'An Islamic late payment charge must go to charity (is_charity = true).');
    });

    it('needs a mandatory gold rule before a gold product can be sold', function () {
        $this->actingAs(staffWith('Product Toggle Status'), 'api')
            ->patchJson('/api/v1/products/gold-pawning/toggle-status')
            ->assertStatus(422)
            ->assertJsonPath('code', 'PRODUCT_NOT_READY');
    });

    it('activates a complete conventional product', function () {
        $user = staffWith('Product Create', 'Product Toggle Status');
        $this->actingAs($user, 'api')->postJson('/api/v1/products', productPayload())->assertCreated();

        $this->actingAs($user, 'api')
            ->patchJson('/api/v1/products/festival-advance/toggle-status')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    });
});

describe('shariah approval', function () {
    beforeEach(function () {
        $this->maker = staffWith('Product Create', 'Product Update', 'Product Toggle Status', 'Product Shariah Submit', 'Approval Request Index');
        $this->board = staffWith('Approval Request Index', 'Product Shariah Approve');

        $this->actingAs($this->maker, 'api')->postJson('/api/v1/products', productPayload([
            'name' => 'Murabaha SME',
            'category_slug' => 'islamic-standard-borrowing',
            'pricing_method' => 'murabaha',
            'early_settlement_rebate_percent' => '50',
            'charges' => [
                ['charge_type' => 'late_payment', 'calculation' => 'fixed', 'value' => '250', 'collection' => 'on_event', 'is_charity' => true],
            ],
        ]))->assertCreated();
    });

    it('cannot go live without shariah approval', function () {
        $this->actingAs($this->maker, 'api')
            ->patchJson('/api/v1/products/murabaha-sme/toggle-status')
            ->assertStatus(409)
            ->assertJsonPath('code', 'SHARIAH_APPROVAL_REQUIRED');
    });

    it('goes live only after a different person with the permission approves it with a reference', function () {
        $approvalId = $this->actingAs($this->maker, 'api')
            ->postJson('/api/v1/products/murabaha-sme/shariah-review', ['reason' => 'New Murabaha product for SME stock'])
            ->assertCreated()
            ->json('data.public_id');

        expect(Product::findBySlug('murabaha-sme')->status)->toBe(ProductStatus::PendingShariah);

        // The maker cannot approve their own request.
        $this->actingAs($this->maker, 'api')
            ->postJson("/api/v1/approval-requests/{$approvalId}/approve", ['reference' => 'SB/2026/14'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'SELF_APPROVAL_NOT_ALLOWED');

        // A viewer without the Shariah permission cannot approve.
        $this->actingAs(staffWith('Approval Request Index'), 'api')
            ->postJson("/api/v1/approval-requests/{$approvalId}/approve", ['reference' => 'SB/2026/14'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'APPROVAL_PERMISSION_REQUIRED');

        // The board must quote its resolution.
        $this->actingAs($this->board, 'api')
            ->postJson("/api/v1/approval-requests/{$approvalId}/approve")
            ->assertStatus(422)
            ->assertJsonPath('code', 'APPROVAL_REFERENCE_REQUIRED');

        $this->actingAs($this->board, 'api')
            ->postJson("/api/v1/approval-requests/{$approvalId}/approve", ['reference' => 'SB/2026/14'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $product = Product::findBySlug('murabaha-sme');

        expect($product->status)->toBe(ProductStatus::Active)
            ->and($product->shariah_reference)->toBe('SB/2026/14');
    });

    it('sends a rejected product back to draft', function () {
        $approvalId = $this->actingAs($this->maker, 'api')
            ->postJson('/api/v1/products/murabaha-sme/shariah-review', ['reason' => 'New Murabaha product'])
            ->json('data.public_id');

        $this->actingAs($this->board, 'api')
            ->postJson("/api/v1/approval-requests/{$approvalId}/reject", ['remarks' => 'Rebate clause needs rewording'])
            ->assertOk();

        expect(Product::findBySlug('murabaha-sme')->status)->toBe(ProductStatus::Draft);
    });

    it('keeps approved terms live until a change is approved', function () {
        $approvalId = $this->actingAs($this->maker, 'api')
            ->postJson('/api/v1/products/murabaha-sme/shariah-review', ['reason' => 'New Murabaha product'])
            ->json('data.public_id');
        $this->actingAs($this->board, 'api')->postJson("/api/v1/approval-requests/{$approvalId}/approve", ['reference' => 'SB/2026/14']);

        // A Shariah term needs a reason and goes to the board; the name changes straight away.
        $this->actingAs($this->maker, 'api')
            ->putJson('/api/v1/products/murabaha-sme', ['default_rate' => '20', 'name' => 'Murabaha SME Stock'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CHANGE_REASON_REQUIRED');

        $changeId = $this->actingAs($this->maker, 'api')
            ->putJson('/api/v1/products/murabaha-sme', ['default_rate' => '20', 'name' => 'Murabaha SME Stock', 'change_reason' => 'Lower profit rate for 2027'])
            ->assertStatus(202)
            ->assertJsonPath('data.product.name', 'Murabaha SME Stock')
            ->assertJsonPath('data.product.rates.default', '24.0000')
            ->json('data.approval_request.public_id');

        $this->actingAs($this->board, 'api')
            ->postJson("/api/v1/approval-requests/{$changeId}/approve", ['reference' => 'SB/2026/31'])
            ->assertOk();

        $product = Product::findBySlug('murabaha-sme');

        expect($product->default_rate)->toBe('20.0000')
            ->and($product->shariah_reference)->toBe('SB/2026/31');
    });
});

describe('quote', function () {
    it('prices a loan without saving anything', function () {
        $user = staffWith('Product Create', 'Product Index');
        $this->actingAs($user, 'api')->postJson('/api/v1/products', productPayload())->assertCreated();

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/products/festival-advance/quote', ['amount' => '100000', 'tenure' => 12, 'start_date' => '2026-01-15'])
            ->assertOk()
            ->assertJsonPath('data.schedule.installment_amount', '10333.33')
            ->assertJsonPath('data.schedule.total_payable', '124000.00')
            ->assertJsonPath('data.upfront_charges.0.amount', '1000.00')
            ->assertJsonPath('data.net_disbursement_amount', '99000.00')
            ->assertJsonPath('data.schedule.lines.0.due_date', '2026-02-15');
    });

    it('refuses amounts outside the product limits', function () {
        $user = staffWith('Product Create', 'Product Index');
        $this->actingAs($user, 'api')->postJson('/api/v1/products', productPayload())->assertCreated();

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/products/festival-advance/quote', ['amount' => '900000', 'tenure' => 12])
            ->assertStatus(422)
            ->assertJsonPath('code', 'QUOTE_OUT_OF_PRODUCT_LIMITS');
    });
});

describe('access', function () {
    it('closes back-office routes to customer logins even with the permission', function () {
        $this->actingAs(userWith('customer', 'Product Index'), 'api')
            ->getJson('/api/v1/products')
            ->assertForbidden();
    });

    it('needs the permission of the action', function () {
        $this->actingAs(staffWith('Product Index'), 'api')
            ->postJson('/api/v1/products', productPayload())
            ->assertForbidden();
    });
});

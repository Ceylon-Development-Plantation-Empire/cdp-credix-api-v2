<?php

declare(strict_types=1);

use App\Finance\Support\Money;
use App\Models\ChargeType;
use App\Models\Collateral;
use App\Models\GlAccount;
use App\Models\GlJournalLine;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);

    // Rahn: no profit at all; the safekeeping fee is 20 a gram a month.
    $product = new Product([
        'slug' => 'gold-rahn',
        'name' => 'Gold Rahn',
        'category_id' => ProductCategory::findBySlug('islamic-standard-borrowing')->id,
        'pricing_method' => 'rahn',
        'default_rate' => '0',
        'min_rate' => '0',
        'max_rate' => '0',
        'min_amount' => '10000',
        'max_amount' => '1000000',
        'min_tenure' => 1,
        'max_tenure' => 12,
        'repayment_frequency' => 'bullet',
        'allows_individual' => true,
        'min_guarantors' => 0,
        'max_guarantors' => 0,
        'early_settlement_allowed' => true,
    ]);
    $product->forceFill(['status' => 'active', 'shariah_approved_at' => now()])->save();
    $product->charges()->create([
        'charge_type' => 'safekeeping_fee',
        'calculation' => 'per_gram_per_month',
        'value' => '20',
        'collection' => 'on_event',
        'recurrence' => 'once',
        'is_charity' => false,
        'is_active' => true,
    ]);

    $this->cashier = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Settle', 'Gold Loan Renew');
    $this->accountant = staffWith('Branch Data All', 'GL Report View');
});

/**
 * 150,000 Rahn for 3 months against 50 g of 22K gold, pledged before the money is paid out.
 */
function rahnLoan(int $branchId, int $daysAgo): Loan
{
    return LoanFixtures::disbursedLoan($branchId, 'gold-rahn', '150000', 3, disbursedOn: CarbonImmutable::today()->subDays($daysAgo), beforeDisburse: function (Loan $loan) use ($daysAgo) {
        (new Collateral)->forceFill([
            'loan_id' => $loan->id,
            'type' => 'gold',
            'description' => 'Two chains',
            'details' => ['gross_weight_g' => '51', 'net_weight_g' => '50', 'karat' => 22, 'rate_per_gram_24k' => '30000'],
            'market_value' => '1375000',
            'ltv_percent' => '75',
            'eligible_amount' => '1031250',
            'valued_on' => today()->subDays($daysAgo)->toDateString(),
            'status' => 'perfected',
            'custody_ref' => 'PKT-0042 / VAULT B',
            'perfected_at' => now(),
        ])->save();
    });
}

function safekeepingCharged(Loan $loan): string
{
    return (string) Money::sum(LoanTransaction::where('loan_id', $loan->id)->where('charge_type_id', ChargeType::where('code', 'safekeeping_fee')->value('id'))->pluck('amount'));
}

function feeIncome(): string
{
    $lines = GlJournalLine::where('gl_account_id', GlAccount::where('slug', 'fee-income')->value('id'))->get(['debit', 'credit']);

    return (string) Money::sum($lines->pluck('debit'))->minus(Money::sum($lines->pluck('credit')));
}

it('charges the first month at disbursement, by weight and never by loan amount', function () {
    $loan = rahnLoan($this->branchId, 0);

    // 50 g × 20 = 1,000 for month 1.
    expect(safekeepingCharged($loan))->toBe('1000.00')
        ->and($loan->outstanding_charges)->toBe('1000.00')
        ->and($loan->outstanding_profit)->toBe('0.00')
        ->and(feeIncome())->toBe('-1000.00');
});

it('adds a month for every month started, once, and collects them all on redemption', function () {
    $loan = rahnLoan($this->branchId, 70);

    $this->artisan('eod:run')->assertSuccessful();
    $this->artisan('eod:run')->assertSuccessful();

    // 70 days: months 1, 2 and 3 have started.
    expect(safekeepingCharged($loan))->toBe('3000.00')
        ->and(LoanTransaction::where('loan_id', $loan->id)->where('charge_type_id', ChargeType::where('code', 'safekeeping_fee')->value('id'))->count())->toBe(3);

    $quote = $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$loan->public_id}/settlement-quote")->assertOk()->json('data');
    expect($quote['total'])->toBe('153000.00')->and($quote['profit_payable'])->toBe('0.00');

    $this->actingAs($this->cashier, 'api')->withHeader('Idempotency-Key', 'redeem-rahn-01')
        ->postJson("/api/v1/loans/{$loan->public_id}/settle", ['amount' => '153000.00', 'channel' => 'cash'])
        ->assertCreated()->assertJsonPath('data.loan.status', 'settled');

    $recon = $this->actingAs($this->accountant, 'api')->getJson('/api/v1/gl/reconciliation')->json('data');
    expect(feeIncome())->toBe('-3000.00')->and($recon['reconciled'])->toBeTrue();
});

it('renews a Rahn for the fees only and starts the new term with its first month', function () {
    $loan = rahnLoan($this->branchId, 45);
    $this->artisan('eod:run')->assertSuccessful();

    $quote = $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$loan->public_id}/renewal-quote")->assertOk()->json('data');
    expect($quote['interest_payable'])->toBe('0.00')
        ->and($quote['charges'])->toBe('2000.00')
        ->and($quote['min_amount_due'])->toBe('2000.00')
        ->and($quote['new_rate'])->toBe('0.0000');

    $this->actingAs($this->cashier, 'api')->withHeader('Idempotency-Key', 'renew-rahn-01')
        ->postJson("/api/v1/loans/{$loan->public_id}/renew", ['amount' => '2000.00', 'channel' => 'cash'])
        ->assertCreated()->assertJsonPath('data.renewal_count', 1);

    $loan->refresh();
    expect($loan->outstanding_principal)->toBe('150000.00')
        ->and($loan->outstanding_charges)->toBe('1000.00')
        ->and(safekeepingCharged($loan))->toBe('3000.00');

    $recon = $this->actingAs($this->accountant, 'api')->getJson('/api/v1/gl/reconciliation')->json('data');
    expect($recon['reconciled'])->toBeTrue();
});

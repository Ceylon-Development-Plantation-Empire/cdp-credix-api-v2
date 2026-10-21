<?php

use App\Enums\SettingType;
use App\Finance\Pricing\GoldPawn;
use App\Finance\Support\Money;
use App\Models\Collateral;
use App\Models\GlAccount;
use App\Models\GlJournalLine;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanTransaction;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class]);
    LoanFixtures::product('gold-pawning', ['default_rate' => '24', 'min_rate' => '12', 'max_rate' => '30', 'min_tenure' => 1, 'max_tenure' => 12]);

    $this->cashier = Fixtures::staffInBranch($this->branchId, 'Loan Index', 'Loan Settle', 'Gold Loan Renew');
    $this->accountant = staffWith('Branch Data All', 'GL Report View');
});

/**
 * 200,000 advanced for 3 months against 50 g of 22K gold valued at 30,000 a gram (24K):
 * value 1,375,000, 75 % LTV = 1,031,250.
 */
function goldLoan(int $branchId, int $daysAgo): Loan
{
    $loan = LoanFixtures::disbursedLoan($branchId, 'gold-pawning', '200000', 3, disbursedOn: CarbonImmutable::today()->subDays($daysAgo));

    (new Collateral)->forceFill([
        'loan_id' => $loan->id,
        'type' => 'gold',
        'description' => 'Necklace and two bangles',
        'details' => ['gross_weight_g' => '52', 'net_weight_g' => '50', 'karat' => 22, 'rate_per_gram_24k' => '30000'],
        'market_value' => '1375000',
        'ltv_percent' => '75',
        'eligible_amount' => '1031250',
        'valued_on' => today()->subDays($daysAgo)->toDateString(),
        'status' => 'perfected',
        'custody_ref' => 'PKT-0001 / VAULT A',
        'perfected_at' => now(),
    ])->save();

    return $loan->refresh();
}

function goldSetting(string $key, SettingType $type, ?string $value): void
{
    Setting::updateOrCreate(['key' => $key], ['group' => 'gold', 'type' => $type, 'value' => $value, 'rules' => ['value' => 'nullable'], 'label' => $key]);
}

function renew($test, Loan $loan, array $body, string $key)
{
    return $test->actingAs($test->cashier, 'api')->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/loans/{$loan->public_id}/renew", $body + ['channel' => 'cash']);
}

function goldGlBalance(string $slug): string
{
    $lines = GlJournalLine::query()
        ->join('gl_journals', 'gl_journals.id', '=', 'gl_journal_lines.gl_journal_id')
        ->where('gl_journals.status', 'posted')
        ->where('gl_account_id', GlAccount::where('slug', $slug)->value('id'))
        ->get(['debit', 'credit']);

    return (string) Money::sum($lines->pluck('debit'))->minus(Money::sum($lines->pluck('credit')));
}

it('renews at maturity for the full term interest and keeps the gold on the same loan', function () {
    $loan = goldLoan($this->branchId, 92);
    $this->artisan('eod:run')->assertSuccessful();
    $old = LoanInstallment::where('loan_id', $loan->id)->first();

    $quote = $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$loan->public_id}/renewal-quote")->assertOk()->json('data');

    expect($quote['interest_payable'])->toBe((string) $old->profit_due)
        ->and($quote['interest_not_charged'])->toBe('0.00')
        ->and($quote['min_principal_payment'])->toBe('0.00')
        ->and($quote['renewal_no'])->toBe(1);

    renew($this, $loan, ['amount' => $quote['min_amount_due']], 'renew-0001')->assertCreated()
        ->assertJsonPath('data.renewal_count', 1)
        ->assertJsonPath('data.maturity_date', $quote['new_maturity_date']);
    renew($this, $loan, ['amount' => $quote['min_amount_due']], 'renew-0001')->assertOk();

    $loan->refresh();
    $new = LoanInstallment::where('loan_id', $loan->id)->where('schedule_version', 2)->sole();

    expect($old->refresh()->status->value)->toBe('rescheduled')
        ->and($loan->status->value)->toBe('active')
        ->and($loan->outstanding_principal)->toBe('200000.00')
        ->and($loan->schedule_starts_on->toDateString())->toBe(today()->toDateString())
        ->and($new->principal_due)->toBe('200000.00')
        ->and($new->profit_due)->toBe((string) GoldPawn::accruedInterest(Money::of('200000'), '24', CarbonImmutable::today(), CarbonImmutable::parse($new->due_date)))
        ->and(LoanTransaction::where('loan_id', $loan->id)->where('type', 'repayment')->count())->toBe(1)
        // The interest recognised at maturity was collected; the principal never left the books.
        ->and(goldGlBalance('interest-receivable'))->toBe('0.00')
        ->and(goldGlBalance('loans-receivable'))->toBe('200000.00');

    $recon = $this->actingAs($this->accountant, 'api')->getJson('/api/v1/gl/reconciliation')->json('data');
    expect($recon['reconciled'])->toBeTrue();
});

it('renews early for the days used only, with a principal pay-down, and the next payoff counts from the renewal', function () {
    $loan = goldLoan($this->branchId, 30);
    $quote = $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$loan->public_id}/renewal-quote?tenure=6")->assertOk()->json('data');

    // 30 days of 24 % on 200,000 (actual/365).
    expect($quote['interest_payable'])->toBe('3945.21')
        ->and((float) $quote['interest_not_charged'])->toBeGreaterThan(0.0)
        ->and($quote['new_tenure'])->toBe(6);

    renew($this, $loan, ['amount' => '3945.21', 'principal_payment' => '50000', 'tenure' => 6], 'renew-0002')
        ->assertStatus(422)->assertJsonPath('code', 'RENEWAL_AMOUNT_MISMATCH');
    renew($this, $loan, ['amount' => '53945.21', 'principal_payment' => '50000', 'tenure' => 6], 'renew-0003')->assertCreated();

    $loan->refresh();
    expect($loan->outstanding_principal)->toBe('150000.00')
        ->and($loan->tenure)->toBe(6)
        ->and(LoanTransaction::where('loan_id', $loan->id)->where('type', 'waiver')->value('amount'))->toBe($quote['interest_not_charged'])
        ->and(goldGlBalance('interest-income'))->toBe('-3945.21')
        ->and(goldGlBalance('loans-receivable'))->toBe('150000.00');

    // Redeeming the same day costs the principal only: interest runs from the renewal, not the first disbursement.
    $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$loan->public_id}/settlement-quote")->assertOk()
        ->assertJsonPath('data.profit_payable', '0.00')
        ->assertJsonPath('data.total', '150000.00');
});

it('asks for a pay-down when the gold is worth less, and stops at the renewal limit', function () {
    $loan = goldLoan($this->branchId, 30);

    // Gold falls to 4,000 a gram: 50 g × 22/24 × 4,000 × 75 % = 137,500 of cover for 200,000.
    goldSetting('gold.rate_per_gram_24k', SettingType::Decimal, '4000');
    $quote = $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$loan->public_id}/renewal-quote")->assertOk()->json('data');
    expect($quote['gold_can_secure'])->toBe('137500.00')->and($quote['min_principal_payment'])->toBe('62500.00');

    renew($this, $loan, ['amount' => $quote['interest_payable']], 'renew-0004')
        ->assertStatus(422)->assertJsonPath('code', 'GOLD_COVER_SHORTFALL');
    renew($this, $loan, ['amount' => $quote['min_amount_due'], 'principal_payment' => '62500'], 'renew-0005')->assertCreated();

    goldSetting('gold.max_renewals', SettingType::Integer, '1');
    $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$loan->public_id}/renewal-quote")
        ->assertStatus(422)->assertJsonPath('code', 'RENEWAL_LIMIT_REACHED');
});

it('renews gold loans only', function () {
    LoanFixtures::product('sme-loan');
    $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);

    $this->actingAs($this->cashier, 'api')->getJson("/api/v1/loans/{$loan->public_id}/renewal-quote")
        ->assertStatus(422)->assertJsonPath('code', 'NOT_A_GOLD_LOAN');
});

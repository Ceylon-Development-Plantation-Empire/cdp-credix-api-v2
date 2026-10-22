<?php

declare(strict_types=1);

use App\Enums\NotificationEvent;
use App\Models\LoanInstallment;
use App\Models\NotificationLog;
use App\Models\RecoveryCase;
use App\Models\Template;
use App\Services\Notifications\NotificationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\TemplateSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\Support\LoanFixtures;

beforeEach(function () {
    $this->branchId = Fixtures::branch();
    $this->seed([ProductCatalogSeeder::class, DocumentRequirementSeeder::class, TemplateSeeder::class]);
    $this->sms = Fixtures::fakeSms();
    LoanFixtures::product('sme-loan', ['min_guarantors' => 0, 'max_guarantors' => 2]);
});

describe('notifications', function () {
    it('tells the borrower when the loan is paid out, logging the result but never the text', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);
        $borrower = $loan->customer;

        $sms = collect($this->sms->sent)->firstWhere('to', $borrower->phone_primary);
        expect($sms['message'])->toContain($loan->loan_no)->toContain('120000.00');

        $log = NotificationLog::where('event', 'loan.disbursed')->first();
        expect($log->status->value)->toBe('sent')
            ->and($log->getAttributes())->not->toHaveKey('message')
            ->and($log->message_hash)->toHaveLength(64);
    });

    it('prefers the product template and the customer language, and never sends the same text twice', function () {
        $product = LoanFixtures::product('sme-loan');
        $customer = LoanFixtures::customer($this->branchId, ['preferred_language' => 'si']);
        $loan = LoanFixtures::approvedLoan($this->branchId, 'sme-loan', '50000', 12, $customer);

        Template::create(['slug' => 'loan.approved', 'channel' => 'sms', 'language' => 'si', 'body' => 'SI global {{application_no}}']);
        Template::create(['slug' => 'loan.approved', 'channel' => 'sms', 'language' => 'en', 'product_id' => $product->id, 'body' => 'SME {{application_no}} {{unknown}}']);
        $notifications = app(NotificationService::class);

        // The product's own (English) template beats the global Sinhala one; unknown placeholders are dropped.
        $notifications->notifyCustomer(NotificationEvent::LoanApproved, $customer, ['application_no' => $loan->application_no], $loan);
        expect(end($this->sms->sent)['message'])->toBe("SME {$loan->application_no} ");

        $again = $notifications->notifyCustomer(NotificationEvent::LoanApproved, $customer, ['application_no' => $loan->application_no], $loan);
        expect($again->status->value)->toBe('skipped')->and(count($this->sms->sent))->toBe(1);
    });

    it('keeps staff alerts and customer messages in one table without mixing them up', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);
        $staff = Fixtures::staffInBranch($this->branchId, 'Loan Index');
        app(NotificationService::class)->notifyStaff($staff, 'info', 'Heads up', 'A test alert');

        expect(DB::table('notifications')->count())->toBe(2)
            ->and(NotificationLog::count())->toBe(1)
            ->and(NotificationLog::first()->notifiable_id)->toBe($loan->customer_id)
            ->and($staff->notifications()->count())->toBe(1)
            ->and($staff->notifications()->first()->channel)->toBe('database');
    });

    it('refuses template placeholders the message does not offer', function () {
        $admin = Fixtures::staffInBranch($this->branchId, 'Template Manage');

        $this->actingAs($admin, 'api')->postJson('/api/v1/templates', ['slug' => 'payment.received', 'channel' => 'sms', 'language' => 'ta', 'body' => 'Paid {{amount}} {{password}}'])
            ->assertStatus(422)->assertJsonPath('code', 'TEMPLATE_PLACEHOLDER_UNKNOWN');

        $this->actingAs($admin, 'api')->postJson('/api/v1/templates', ['slug' => 'payment.received', 'channel' => 'sms', 'language' => 'en', 'body' => 'Paid {{amount}}'])
            ->assertStatus(409)->assertJsonPath('code', 'TEMPLATE_EXISTS');

        $this->actingAs($admin, 'api')->postJson('/api/v1/templates', ['slug' => 'payment.received', 'channel' => 'sms', 'language' => 'ta', 'body' => 'Paid {{amount}}'])
            ->assertCreated();
    });
});

describe('recovery', function () {
    it('opens a case at 90 DPD with notices to borrower and guarantor, and moves it to an agent at 180', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(130));
        $guarantor = LoanFixtures::customer($this->branchId);
        $loan->parties()->create(['customer_id' => $guarantor->id, 'role' => 'guarantor']);
        $manager = Fixtures::staffInBranch($this->branchId, 'Recovery Case Assign', 'Recovery Case Index');

        $this->artisan('eod:run')->assertSuccessful();

        $case = RecoveryCase::first();
        expect($case->stage->value)->toBe('notice')
            ->and($case->opened_dpd)->toBeGreaterThanOrEqual(90)
            ->and(collect($this->sms->sent)->pluck('to'))->toContain($loan->customer->phone_primary, $guarantor->phone_primary)
            ->and(collect($this->sms->sent)->pluck('message')->implode(' '))->toContain('RECOVERY NOTICE');

        // Running again opens nothing new.
        $this->artisan('eod:run')->assertSuccessful();
        expect(RecoveryCase::count())->toBe(1);

        // 90 more days unpaid: past 180 DPD the case needs an agent and the manager is told.
        $this->travel(90)->days();
        $this->artisan('eod:run')->assertSuccessful();

        expect($case->refresh()->stage->value)->toBe('internal')
            ->and($manager->notifications()->where('data->type', 'RECOVERY_AGENT_NEEDED')->count())->toBe(1);
    });

    it('lets an agency see and work only the cases assigned to it', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(130));
        $this->artisan('eod:run')->assertSuccessful();
        $case = RecoveryCase::first();
        $borrowerPhone = $loan->customer->phone_primary;

        $manager = Fixtures::staffInBranch($this->branchId, 'Recovery Case Assign', 'Recovery Case Index');
        $agency = userWith('agency');
        $otherAgency = userWith('agency');
        $plainStaff = Fixtures::staffInBranch($this->branchId);

        // Internal agents need Recovery Case Work; external cases go to agency logins only.
        $this->actingAs($manager, 'api')->postJson("/api/v1/recovery-cases/{$case->public_id}/assign", ['user_id' => $plainStaff->id, 'stage' => 'internal'])
            ->assertStatus(422)->assertJsonPath('code', 'RECOVERY_AGENT_INVALID');
        $this->actingAs($manager, 'api')->postJson("/api/v1/recovery-cases/{$case->public_id}/assign", ['user_id' => $agency->id, 'stage' => 'external'])
            ->assertOk()->assertJsonPath('data.stage', 'external');

        $this->actingAs($agency, 'api')->getJson('/api/v1/agency/recovery-cases')->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.customer.phone_primary', $borrowerPhone);

        $this->actingAs($agency, 'api')->postJson("/api/v1/agency/recovery-cases/{$case->public_id}/activities", ['type' => 'promise_to_pay', 'remarks' => 'Will pay on Friday'])
            ->assertStatus(422)->assertJsonFragment(['field' => 'promised_amount']);
        $this->actingAs($agency, 'api')->postJson("/api/v1/agency/recovery-cases/{$case->public_id}/activities", ['type' => 'call', 'remarks' => 'Customer answered'])
            ->assertCreated();

        $this->actingAs($otherAgency, 'api')->getJson("/api/v1/agency/recovery-cases/{$case->public_id}")->assertNotFound();
        // An agency login can never reach staff routes.
        $this->actingAs($agency, 'api')->getJson('/api/v1/loans')->assertForbidden();
    });

    it('resolves the case once the arrears are paid', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(130));
        $this->artisan('eod:run')->assertSuccessful();
        $loan->refresh();

        $cashier = Fixtures::staffInBranch($this->branchId, 'Payment Create');
        $this->actingAs($cashier, 'api')->withHeader('Idempotency-Key', 'pay-arrears-01')
            ->postJson('/api/v1/payments', ['loan_public_id' => $loan->public_id, 'amount' => (string) $loan->arrears_amount, 'channel' => 'cash'])
            ->assertCreated();

        expect(RecoveryCase::first()->status->value)->toBe('resolved')
            ->and($loan->refresh()->days_past_due)->toBe(0);
    });
});

describe('credit score and reports', function () {
    it('scores on-time and late installments', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(70));
        $this->artisan('eod:run')->assertSuccessful();

        $staff = Fixtures::staffInBranch($this->branchId, 'Customer Index');
        // Two installments are past their grace period and unpaid.
        $score = $this->actingAs($staff, 'api')->getJson("/api/v1/customers/{$loan->customer->public_id}/credit-score")
            ->assertOk()->assertJsonPath('data.missed', 2)->assertJsonPath('data.score', 480)->json('data');

        // The record behind the score, per loan.
        $first = LoanInstallment::where('loan_id', $loan->id)->orderBy('installment_no')->first();
        expect($score['loans'])->toHaveCount(1)
            ->and($score['loans'][0]['loan_no'])->toBe($loan->loan_no)
            ->and($score['loans'][0]['missed'])->toBe(2)
            ->and($score['worst_days_late'])->toBe((int) $first->due_date->diffInDays(today()))
            ->and($score['loans'][0]['worst_days_late'])->toBe($score['worst_days_late']);
    });

    it('charts what was paid out and collected per month for the dashboard', function () {
        $loan = LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12);
        $manager = Fixtures::staffInBranch($this->branchId, 'Dashboard View', 'Payment Create');
        $this->actingAs($manager, 'api')->withHeader('Idempotency-Key', 'trend-pay-0001')
            ->postJson('/api/v1/payments', ['loan_public_id' => $loan->public_id, 'amount' => '5000', 'channel' => 'cash'])->assertCreated();

        $trend = $this->actingAs($manager, 'api')->getJson('/api/v1/dashboard/trend?months=3')->assertOk()->json('data');

        expect($trend)->toHaveCount(3)
            ->and(end($trend))->toBe(['month' => today()->format('Y-m'), 'disbursed' => '120000.00', 'collected' => '5000.00'])
            ->and($trend[0]['disbursed'])->toBe('0.00');

        $this->actingAs($manager, 'api')->getJson('/api/v1/dashboard/trend?months=24')->assertStatus(422);
    });

    it('reports the portfolio of the user branch only', function () {
        LoanFixtures::disbursedLoan($this->branchId, 'sme-loan', '120000', 12, disbursedOn: CarbonImmutable::today()->subDays(45));
        $this->artisan('eod:run')->assertSuccessful();

        $manager = Fixtures::staffInBranch($this->branchId, 'Report View', 'Dashboard View');
        $kandy = Fixtures::staffInBranch(Fixtures::branch('Kandy'), 'Report View', 'Dashboard View');

        $this->actingAs($manager, 'api')->getJson('/api/v1/reports/portfolio')->assertOk()
            ->assertJsonPath('data.0.loans', 1)
            ->assertJsonPath('data.0.outstanding_principal', fn ($v) => (float) $v > 0);

        $aging = collect($this->actingAs($manager, 'api')->getJson('/api/v1/reports/arrears-aging')->json('data'))->keyBy('bucket');
        expect($aging['1-30']['loans'])->toBe(1);

        $this->actingAs($manager, 'api')->getJson('/api/v1/dashboard/overview')->assertOk()
            ->assertJsonPath('data.active_loans', 1)
            ->assertJsonPath('data.disbursed_this_month', fn ($v) => in_array($v, ['0.00', '120000.00'], true));

        $this->actingAs($kandy, 'api')->getJson('/api/v1/dashboard/overview')->assertOk()->assertJsonPath('data.active_loans', 0);
        $this->actingAs($kandy, 'api')->getJson('/api/v1/reports/portfolio')->assertOk()->assertJsonCount(0, 'data');
    });
});

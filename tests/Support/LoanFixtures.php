<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\DocumentStatus;
use App\Enums\KycStatus;
use App\Models\Customer;
use App\Models\CustomerFinancial;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Loan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\Loans\DisbursementService;
use App\Services\Loans\LoanPartyService;
use App\Services\Loans\LoanPricingService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Ready-made loan test data: sellable products, KYC-verified customers with their documents,
 * and approvers with an approval limit. The workflow itself is always driven through the API.
 */
final class LoanFixtures
{
    private static int $nicSequence = 0;

    /**
     * Turn a seeded (draft) product into a sellable one with real terms.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function product(string $slug, array $overrides = []): Product
    {
        $product = Product::findBySlug($slug);

        $product->forceFill(array_merge([
            'default_rate' => '18',
            'min_rate' => '12',
            'max_rate' => '24',
            'min_amount' => '10000',
            'max_amount' => '1000000',
            'status' => 'active',
        ], $overrides))->save();

        return $product->refresh();
    }

    /**
     * An employed customer born in 1985 whose details are confirmed (KYC) and whose borrower and
     * guarantor documents are uploaded and verified.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function customer(int $branchId, array $overrides = [], bool $withDocuments = true): Customer
    {
        $sequence = ++self::$nicSequence;
        $nic = '1985'.str_pad((string) $sequence, 8, '0', STR_PAD_LEFT);

        $customer = new Customer(array_merge([
            'branch_id' => $branchId,
            'id_type' => 'nic',
            'id_number' => $nic,
            'full_name' => "Customer {$sequence}",
            'name_with_initials' => "C. {$sequence}",
            'date_of_birth' => '1985-01-01',
            'gender' => 'male',
            'phone_primary' => '+9477'.str_pad((string) $sequence, 7, '0', STR_PAD_LEFT),
            'address_line_1' => '1 Main Street',
            'city' => 'Colombo',
            'gn_division' => 'Kollupitiya',
            'ds_division' => 'Colombo',
            'district' => 'Colombo',
            'province' => 'Western',
            'employment_status' => 'employed',
            'occupation' => 'Teacher',
            'employer_name' => 'Ministry of Education',
        ], $overrides));

        // The model encrypts the ID number and writes its blind index.
        $customer->save();

        $customer->forceFill([
            'kyc_status' => KycStatus::Verified,
            'kyc_verified_at' => now(),
            'kyc_expires_at' => now()->addYear(),
            'kyc_snapshot_hash' => $customer->kycFingerprint(),
        ])->save();

        if ($withDocuments) {
            foreach (['nic-copy', 'gn-confirmation-letter', 'ds-confirmation-letter', 'salary-confirmation-letter', 'employment-letter', 'salary-slip', 'salary-slip', 'salary-slip'] as $type) {
                self::verifiedDocument($customer, $type);
            }
        }

        return $customer->refresh();
    }

    /**
     * A verified document row (no real file is needed to test the rules).
     */
    public static function verifiedDocument(object $owner, string $typeSlug): Document
    {
        $document = new Document([
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => $owner->getKey(),
            'document_type_id' => DocumentType::findBySlug($typeSlug)->id,
            'original_name' => "{$typeSlug}.pdf",
            'path' => 'documents/test/'.Str::ulid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'issued_on' => now()->subDays(5)->toDateString(),
        ]);

        $document->forceFill(['status' => DocumentStatus::Verified, 'verified_at' => now()])->save();

        return $document;
    }

    /**
     * Verified monthly income (and optionally an existing loan installment).
     */
    public static function income(Customer $customer, string $monthlyIncome, ?string $existingInstallment = null): void
    {
        $income = new CustomerFinancial(['customer_id' => $customer->id, 'kind' => 'income', 'category' => 'salary', 'amount' => $monthlyIncome]);
        $income->forceFill(['is_verified' => true, 'verified_at' => now()])->save();

        if ($existingInstallment !== null) {
            CustomerFinancial::create([
                'customer_id' => $customer->id, 'kind' => 'liability', 'category' => 'bank_loan',
                'amount' => '500000', 'monthly_obligation' => $existingInstallment,
            ]);
        }
    }

    /**
     * An individual loan paid out on $disbursedOn by the real DisbursementService, so the schedule,
     * ledger and balances are exactly what production creates. The steps before (review, approval,
     * OTP acceptance) are set directly; they have their own tests.
     */
    public static function disbursedLoan(int $branchId, string $productSlug, string $amount, int $tenure, ?Customer $borrower = null, ?CarbonImmutable $disbursedOn = null, ?callable $beforeDisburse = null): Loan
    {
        $loan = self::approvedLoan($branchId, $productSlug, $amount, $tenure, $borrower);
        $loan->forceFill(['status' => 'accepted'])->save();

        $party = $loan->parties()->first();
        $party->forceFill([
            'consented_at' => now(),
            'consent_hash' => hash('sha256', json_encode(app(LoanPartyService::class)->offerTerms($loan->refresh()))),
        ])->save();

        self::verifiedDocument($loan, 'loan-agreement');

        if ($beforeDisburse) {
            $beforeDisburse($loan);
        }

        $disburser = Fixtures::staffInBranch($branchId, 'Loan Disburse');
        $restore = Carbon::getTestNow();

        if ($disbursedOn) {
            Carbon::setTestNow($disbursedOn);
        }

        app(DisbursementService::class)->disburse($loan, ['channel' => 'cash'], Str::random(20), $disburser);
        Carbon::setTestNow($restore);

        return $loan->refresh();
    }

    /**
     * An individual loan approved today and waiting for the borrower to accept the offer.
     */
    public static function approvedLoan(int $branchId, string $productSlug, string $amount, int $tenure, ?Customer $borrower = null): Loan
    {
        $product = Product::findBySlug($productSlug);
        $borrower ??= self::customer($branchId);
        $pricing = app(LoanPricingService::class);

        $loan = new Loan([
            'product_id' => $product->id,
            'branch_id' => $branchId,
            'borrower_type' => 'individual',
            'customer_id' => $borrower->id,
            'requested_amount' => $amount,
            'tenure' => $tenure,
        ]);

        $loan->forceFill([
            'finance_basis' => $product->financeBasis(),
            'pricing_method' => $product->pricing_method,
            'repayment_frequency' => $product->repayment_frequency,
            'rate' => $product->default_rate,
            'terms_snapshot' => $pricing->snapshot($product),
            'approved_amount' => $amount,
            'approved_at' => now(),
            'status' => 'approved',
        ])->save();

        $loan->forceFill($pricing->price($loan, $amount, $loan->rate)->columns())->save();

        $party = $loan->parties()->create(['customer_id' => $borrower->id, 'role' => 'borrower']);
        $party->forceFill(['open_loan_key' => $borrower->id])->save();

        return $loan->refresh();
    }

    /**
     * An Islamic Murabaha product, live (Shariah approval is tested in the catalogue tests).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function murabahaProduct(array $overrides = []): Product
    {
        // The catalogue seeder already holds a Murabaha product with this slug: the fixture sets it to the terms the tests need.
        $product = Product::where('slug', 'murabaha-asset-finance')->first() ?? new Product;
        $product->fill(array_merge([
            'slug' => 'murabaha-asset-finance',
            'name' => 'Murabaha Asset Finance',
            'category_id' => ProductCategory::findBySlug('islamic-standard-borrowing')->id,
            'pricing_method' => 'murabaha',
            'default_rate' => '15',
            'min_rate' => '10',
            'max_rate' => '20',
            'min_amount' => '10000',
            'max_amount' => '1000000',
            'min_tenure' => 6,
            'max_tenure' => 60,
            'repayment_frequency' => 'monthly',
            'allows_individual' => true,
            'min_guarantors' => 0,
            'max_guarantors' => 0,
            'early_settlement_allowed' => true,
            'early_settlement_rebate_percent' => '50',
        ], $overrides));

        $product->forceFill(['status' => 'active', 'shariah_approved_at' => now()])->save();

        return $product;
    }

    /**
     * A staff member who can approve loans up to $limit.
     */
    public static function approver(int $branchId, string $limit, string ...$permissions): User
    {
        $user = Fixtures::staffInBranch($branchId, 'Loan Approve', ...$permissions);

        $role = Role::create(['name' => 'Approver '.Str::random(6), 'guard_name' => 'api']);
        $role->forceFill(['max_approval_amount' => $limit])->save();
        $user->assignRole($role);

        return $user->fresh();
    }
}

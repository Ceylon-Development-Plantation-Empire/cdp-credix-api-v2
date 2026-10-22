<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\BorrowerGroupStatus;
use App\Enums\BorrowerType;
use App\Enums\LoanActivityType;
use App\Enums\LoanStatus;
use App\Enums\PartyRole;
use App\Enums\ProductStatus;
use App\Enums\RepaymentFrequency;
use App\Exceptions\BusinessRuleException;
use App\Finance\Support\Money;
use App\Models\BorrowerGroup;
use App\Models\BorrowerGroupMember;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Product;
use App\Models\User;
use App\Traits\ActivityLogTrait;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Applications: create, edit (draft / returned only) and submit.
 *
 * Who borrows:
 * - individual: one borrower;
 * - joint: a borrower and one or more co-borrowers, all liable for the whole loan;
 * - group: a facility on a borrower group with one member loan per member (design 4.3).
 */
class LoanApplicationService
{
    use ActivityLogTrait;

    public function __construct(
        protected LoanWorkflowService $workflow,
        protected LoanPricingService $pricing,
        protected EligibilityService $eligibility,
        protected AffordabilityService $affordability,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated CreateLoanRequest data.
     */
    public function create(array $data, User $user): Loan
    {
        return DB::transaction(function () use ($data, $user) {
            $product = Product::with('category')->where('slug', $data['product_slug'])->first();

            if (! $product || $product->status !== ProductStatus::Active) {
                throw new BusinessRuleException('PRODUCT_NOT_AVAILABLE', 'This product is not available for new applications.', 422);
            }

            $type = BorrowerType::from($data['borrower_type']);
            $this->assertBorrowerTypeAllowed($product, $type);

            $terms = $this->pricing->snapshot($product);
            $tenure = (int) $data['tenure'];
            $this->assertTenure($terms, $tenure);

            return $type === BorrowerType::Group
                ? $this->createGroupFacility($product, $data, $tenure, $user)
                : $this->createIndividualOrJoint($product, $type, $data, $tenure, $user);
        });
    }

    /**
     * Change an application before submission or after it was returned for correction.
     *
     * Changing the amount or tenure clears the guarantors' consent: they agreed to guarantee the
     * old figures, so they must confirm the new ones.
     *
     * @param  array<string, mixed>  $data  Validated UpdateLoanRequest data.
     */
    public function update(Loan $loan, array $data, User $user): Loan
    {
        return DB::transaction(function () use ($loan, $data, $user) {
            $loan = $this->workflow->lock($loan);
            $this->assertEditable($loan);

            $terms = $this->pricing->termsOf($loan);
            $tenure = (int) ($data['tenure'] ?? $loan->tenure);
            $this->assertTenure($terms, $tenure);

            $before = [(string) Money::of($loan->requested_amount), $loan->tenure];

            $loan->fill(array_intersect_key($data, array_flip(['purpose', 'meta'])) + ['tenure' => $tenure, 'updated_by' => $user->id]);

            if ($loan->isGroupFacility()) {
                if (array_key_exists('members', $data)) {
                    $this->syncMembers($loan, $data['members'], $user);
                }

                $loan->memberLoans()->open()->update(['tenure' => $tenure]);
                $loan->requested_amount = (string) Money::sum($loan->memberLoans()->open()->pluck('requested_amount'));
            } else {
                if (isset($data['requested_amount'])) {
                    $this->assertAmount($terms, $data['requested_amount']);
                    $loan->requested_amount = $data['requested_amount'];
                }

                if ($loan->borrower_type === BorrowerType::Joint && array_key_exists('co_borrower_public_ids', $data)) {
                    $this->syncCoBorrowers($loan, $data['co_borrower_public_ids']);
                }
            }

            $loan->save();

            if ($before !== [(string) Money::of($loan->requested_amount), $loan->tenure]) {
                $loan->guarantors()->update(['consented_at' => null, 'consent_hash' => null]);
            }

            $this->workflow->record($loan, LoanActivityType::Update, $user, 'Application updated', ['fields' => array_keys($data)]);

            return $loan->refresh();
        });
    }

    /**
     * Submit (or resubmit after correction). The product terms are frozen into the loan here.
     */
    public function submit(Loan $loan, User $user): Loan
    {
        return DB::transaction(function () use ($loan, $user) {
            $loan = $this->workflow->lock($loan);

            if (! $loan->status->canMoveTo(LoanStatus::Submitted)) {
                throw new BusinessRuleException('LOAN_INVALID_TRANSITION', "A loan that is {$loan->status->value} cannot be submitted.", 409);
            }

            $product = $loan->product()->with(['category', 'charges', 'collateralRules'])->first();

            if ($product->status !== ProductStatus::Active) {
                throw new BusinessRuleException('PRODUCT_NOT_AVAILABLE', 'This product is no longer available. Cancel the application and apply for another product.', 422);
            }

            $terms = $this->pricing->snapshot($product);
            $this->assertBorrowerTypeAllowed($product, $loan->borrower_type);
            $this->assertTenure($terms, $loan->tenure);

            $borrowingLoans = $this->borrowingLoans($loan);
            $ignore = $borrowingLoans->pluck('id')->push($loan->id)->unique()->values()->all();

            if ($loan->isGroupFacility()) {
                $this->assertGroupReady($loan, $terms, $borrowingLoans);
            }

            if ($loan->borrower_type === BorrowerType::Joint && $loan->parties()->where('role', PartyRole::CoBorrower->value)->doesntExist()) {
                throw new BusinessRuleException('CO_BORROWER_REQUIRED', 'A joint loan needs at least one co-borrower.', 422);
            }

            $this->assertGuarantorCount($loan, $terms, atLeastMinimum: false);

            foreach ($borrowingLoans as $each) {
                $this->assertAmount($terms, $each->requested_amount);

                // L-1 and L-2: every borrowing person, KYC included.
                foreach ($each->borrowingParties()->with('customer')->get() as $party) {
                    $this->eligibility->assertNone(
                        $this->eligibility->borrowerProblems($party->customer, $loan->tenure, RepaymentFrequency::from($terms['repayment_frequency']), true, $ignore),
                        $party->customer,
                    );
                }

                $this->applyTerms($each, $terms);
                $pricing = $this->pricing->price($each, $each->requested_amount, $each->rate);
                $each->forceFill($pricing->columns());
                $this->affordability->assess($each, $pricing, verifiedIncomeOnly: false);
                $each->save();
            }

            if ($loan->isGroupFacility()) {
                $this->applyTerms($loan, $terms);
                $this->sumMemberColumns($loan, $borrowingLoans);
                $loan->save();
            }

            return $this->workflow->transition($loan, LoanStatus::Submitted, $user, $loan->status === LoanStatus::Returned ? 'Resubmitted after correction' : null);
        });
    }

    /**
     * The loans that carry borrowers: the member loans of a group facility, else the loan itself.
     *
     * @return Collection<int, Loan>
     */
    public function borrowingLoans(Loan $loan): Collection
    {
        return $loan->isGroupFacility() ? $loan->memberLoans()->open()->get() : collect([$loan]);
    }

    /**
     * The terms snapshot a submitted loan runs on. Draft loans copy the product's terms here.
     *
     * @param  array<string, mixed>  $terms
     */
    protected function applyTerms(Loan $loan, array $terms): void
    {
        $loan->forceFill([
            'finance_basis' => $terms['finance_basis'],
            'pricing_method' => $terms['pricing_method'],
            'repayment_frequency' => $terms['repayment_frequency'],
            'rate' => $terms['default_rate'],
            'terms_snapshot' => $terms,
        ]);
    }

    /**
     * A group facility shows the totals of its member loans.
     *
     * @param  Collection<int, Loan>  $memberLoans
     */
    public function sumMemberColumns(Loan $facility, Collection $memberLoans): void
    {
        $columns = ['requested_amount', 'approved_amount', 'financed_amount', 'profit_amount', 'installment_amount', 'upfront_charges', 'net_disbursement_amount', 'residual_value'];

        foreach ($columns as $column) {
            $values = $memberLoans->pluck($column)->filter(fn ($v) => $v !== null);
            $facility->{$column} = $values->isEmpty() && $column === 'approved_amount' ? null : (string) Money::sum($values);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createIndividualOrJoint(Product $product, BorrowerType $type, array $data, int $tenure, User $user): Loan
    {
        $terms = $this->pricing->snapshot($product);
        $this->assertAmount($terms, $data['requested_amount']);

        $borrower = $this->findCustomer($data['customer_public_id']);
        $coBorrowers = $type === BorrowerType::Joint ? $this->findCustomers($data['co_borrower_public_ids'] ?? []) : collect();

        if ($type === BorrowerType::Joint && $coBorrowers->isEmpty()) {
            throw new BusinessRuleException('CO_BORROWER_REQUIRED', 'A joint loan needs at least one co-borrower.', 422);
        }

        if ($coBorrowers->contains('id', $borrower->id)) {
            throw new BusinessRuleException('DUPLICATE_PARTY', 'The borrower cannot also be a co-borrower.', 422);
        }

        // KYC is required at submission, not now, so the officer can start the application first.
        foreach ([$borrower, ...$coBorrowers] as $customer) {
            $this->eligibility->assertNone($this->eligibility->borrowerProblems($customer, $tenure, $product->repayment_frequency, false), $customer);
        }

        $loan = $this->newLoan($product, [
            'branch_id' => $borrower->branch_id,
            'borrower_type' => $type,
            'customer_id' => $borrower->id,
            'requested_amount' => $data['requested_amount'],
            'tenure' => $tenure,
            'purpose' => $data['purpose'] ?? null,
            'meta' => $data['meta'] ?? null,
        ], $user);

        $loan->parties()->create(['customer_id' => $borrower->id, 'role' => PartyRole::Borrower]);

        foreach ($coBorrowers as $coBorrower) {
            $loan->parties()->create(['customer_id' => $coBorrower->id, 'role' => PartyRole::CoBorrower]);
        }

        $this->logActivity('CREATE', 'Loan', "Created application {$loan->application_no}");

        return $loan->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createGroupFacility(Product $product, array $data, int $tenure, User $user): Loan
    {
        $group = BorrowerGroup::where('public_id', $data['borrower_group_public_id'])->first();

        if (! $group) {
            throw new BusinessRuleException('BORROWER_GROUP_NOT_FOUND', 'Borrower group not found.', 404);
        }

        if ($group->status !== BorrowerGroupStatus::Active) {
            throw new BusinessRuleException('GROUP_NOT_ACTIVE', 'Only an active group can apply for a loan.', 422);
        }

        if (Loan::topLevel()->open()->where('borrower_group_id', $group->id)->where('status', '!=', LoanStatus::Draft->value)->exists()) {
            throw new BusinessRuleException('GROUP_HAS_OPEN_LOAN', 'This group already has an open loan or application.', 409);
        }

        $facility = $this->newLoan($product, [
            'branch_id' => $group->branch_id,
            'borrower_type' => BorrowerType::Group,
            'borrower_group_id' => $group->id,
            'requested_amount' => '0',
            'tenure' => $tenure,
            'purpose' => $data['purpose'] ?? null,
            'meta' => $data['meta'] ?? null,
        ], $user);

        $this->syncMembers($facility, $data['members'], $user);
        $facility->requested_amount = (string) Money::sum($facility->memberLoans()->open()->pluck('requested_amount'));
        $facility->save();

        $this->logActivity('CREATE', 'Loan', "Created group application {$facility->application_no} for {$group->group_no}");

        return $facility->refresh();
    }

    /**
     * GR-3: while the application is not yet approved, members can be added, removed or have their
     * amounts changed. Each member gets (or keeps) their own member loan.
     *
     * @param  list<array{customer_public_id: string, amount: string}>  $members
     */
    protected function syncMembers(Loan $facility, array $members, User $user): void
    {
        $terms = $this->pricing->termsOf($facility);
        $wanted = collect();

        foreach ($members as $row) {
            $customer = $this->findCustomer($row['customer_public_id']);

            $isMember = BorrowerGroupMember::where('borrower_group_id', $facility->borrower_group_id)
                ->where('customer_id', $customer->id)->whereNull('left_on')->exists();

            if (! $isMember) {
                throw new BusinessRuleException('NOT_A_GROUP_MEMBER', "{$customer->full_name} ({$customer->customer_no}) is not a current member of this group.", 422);
            }

            $this->assertAmount($terms, $row['amount']);
            $wanted->put($customer->id, ['customer' => $customer, 'amount' => $row['amount']]);
        }

        $existing = $facility->memberLoans()->open()->get()->keyBy('customer_id');

        // Members taken off the application.
        foreach ($existing->except($wanted->keys()->all()) as $memberLoan) {
            $this->workflow->cancelMemberLoan($memberLoan, $user, 'Removed from the group application');
        }

        foreach ($wanted as $customerId => $row) {
            if ($memberLoan = $existing->get($customerId)) {
                $memberLoan->forceFill(['requested_amount' => $row['amount'], 'updated_by' => $user->id])->save();

                continue;
            }

            $this->eligibility->assertNone(
                $this->eligibility->borrowerProblems($row['customer'], $facility->tenure, RepaymentFrequency::from($terms['repayment_frequency']), false),
                $row['customer'],
            );

            $memberLoan = $this->newLoan($facility->product, [
                'parent_id' => $facility->id,
                'branch_id' => $facility->branch_id,
                'borrower_type' => BorrowerType::Group,
                'borrower_group_id' => $facility->borrower_group_id,
                'customer_id' => $customerId,
                'requested_amount' => $row['amount'],
                'tenure' => $facility->tenure,
                'purpose' => $facility->purpose,
            ], $user);

            $memberLoan->forceFill(['status' => $facility->status])->save();
            $memberLoan->parties()->create(['customer_id' => $customerId, 'role' => PartyRole::GroupMember]);
        }
    }

    /**
     * @param  list<string>  $publicIds
     */
    protected function syncCoBorrowers(Loan $loan, array $publicIds): void
    {
        $customers = $this->findCustomers($publicIds);

        if ($customers->isEmpty()) {
            throw new BusinessRuleException('CO_BORROWER_REQUIRED', 'A joint loan needs at least one co-borrower.', 422);
        }

        if ($customers->contains('id', $loan->customer_id) || $loan->guarantors()->whereIn('customer_id', $customers->pluck('id'))->exists()) {
            throw new BusinessRuleException('DUPLICATE_PARTY', 'A person can have only one role on a loan.', 422);
        }

        $loan->parties()->where('role', PartyRole::CoBorrower->value)->whereNotIn('customer_id', $customers->pluck('id'))->delete();

        foreach ($customers as $customer) {
            if ($loan->parties()->where('customer_id', $customer->id)->exists()) {
                continue;
            }

            $this->eligibility->assertNone($this->eligibility->borrowerProblems($customer, $loan->tenure, $loan->repayment_frequency, false, [$loan->id]), $customer);
            $loan->parties()->create(['customer_id' => $customer->id, 'role' => PartyRole::CoBorrower]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function newLoan(Product $product, array $attributes, User $user): Loan
    {
        $loan = new Loan($attributes + [
            'product_id' => $product->id,
            'assigned_officer_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        // Draft terms come from the product; they are copied again (frozen) at submission.
        $loan->forceFill([
            'finance_basis' => $product->financeBasis(),
            'pricing_method' => $product->pricing_method,
            'repayment_frequency' => $product->repayment_frequency,
            'rate' => $product->default_rate,
        ])->save();

        if ($loan->parent_id === null) {
            $this->workflow->record($loan, LoanActivityType::StatusChange, $user, 'Application created');
        }

        return $loan;
    }

    /**
     * GR-4 and GR-5 at submission: group size within the product limits and every member still
     * a current member of the group.
     *
     * @param  array<string, mixed>  $terms
     * @param  Collection<int, Loan>  $memberLoans
     */
    public function assertGroupReady(Loan $facility, array $terms, Collection $memberLoans): void
    {
        $count = $memberLoans->count();
        $min = $terms['min_group_members'] ?? 1;
        $max = $terms['max_group_members'] ?? PHP_INT_MAX;

        if ($count < $min || $count > $max) {
            throw new BusinessRuleException('GROUP_SIZE_OUT_OF_RANGE', "A group loan on this product needs between {$min} and {$max} members (this application has {$count}).", 422);
        }

        $current = BorrowerGroupMember::where('borrower_group_id', $facility->borrower_group_id)->whereNull('left_on')->pluck('customer_id');
        $left = $memberLoans->pluck('customer_id')->diff($current);

        if ($left->isNotEmpty()) {
            throw new BusinessRuleException('NOT_A_GROUP_MEMBER', 'Some members on the application have left the group. Update the application.', 422, [
                'customer_nos' => Customer::whereIn('id', $left)->pluck('customer_no')->all(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    public function assertGuarantorCount(Loan $loan, array $terms, bool $atLeastMinimum): void
    {
        $count = $loan->guarantors()->count();

        if ($count > $terms['max_guarantors']) {
            throw new BusinessRuleException('GUARANTOR_COUNT_EXCEEDED', "This product allows at most {$terms['max_guarantors']} guarantor(s).", 422);
        }

        if ($atLeastMinimum && $count < $terms['min_guarantors']) {
            throw new BusinessRuleException('GUARANTORS_REQUIRED', "This product needs at least {$terms['min_guarantors']} guarantor(s).", 422);
        }
    }

    /**
     * L-4: amount within the product band.
     *
     * @param  array<string, mixed>  $terms
     */
    public function assertAmount(array $terms, BigDecimal|string $amount): void
    {
        $amount = Money::of($amount);

        if ($amount->isLessThan($terms['min_amount']) || $amount->isGreaterThan($terms['max_amount'])) {
            throw new BusinessRuleException('AMOUNT_OUT_OF_PRODUCT_LIMITS', "The amount must be between {$terms['min_amount']} and {$terms['max_amount']}.", 422);
        }
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    protected function assertTenure(array $terms, int $tenure): void
    {
        if ($tenure < $terms['min_tenure'] || $tenure > $terms['max_tenure']) {
            throw new BusinessRuleException('TENURE_OUT_OF_PRODUCT_LIMITS', "The tenure must be between {$terms['min_tenure']} and {$terms['max_tenure']} installments.", 422);
        }
    }

    protected function assertBorrowerTypeAllowed(Product $product, BorrowerType $type): void
    {
        $allowed = match ($type) {
            BorrowerType::Individual => $product->allows_individual,
            BorrowerType::Joint => $product->allows_joint,
            BorrowerType::Group => $product->allows_group,
        };

        if (! $allowed) {
            throw new BusinessRuleException('BORROWER_TYPE_NOT_ALLOWED', "{$product->name} does not offer {$type->value} loans.", 422);
        }
    }

    protected function assertEditable(Loan $loan): void
    {
        if ($loan->isMemberLoan()) {
            throw new BusinessRuleException('MEMBER_LOAN_ACTION', 'This is a member loan of a group facility. Edit the group facility instead.', 422);
        }

        if (! $loan->status->isEditable()) {
            throw new BusinessRuleException('LOAN_NOT_EDITABLE', 'An application can only be changed while it is a draft or after it was returned for correction.', 409);
        }
    }

    /**
     * Customers are found through the branch scope: a customer of another branch is "not found".
     */
    protected function findCustomer(string $publicId): Customer
    {
        $customer = Customer::where('public_id', $publicId)->first();

        if (! $customer) {
            throw new BusinessRuleException('CUSTOMER_NOT_FOUND', 'Customer not found.', 404);
        }

        return $customer;
    }

    /**
     * @param  list<string>  $publicIds
     * @return Collection<int, Customer>
     */
    protected function findCustomers(array $publicIds): Collection
    {
        return collect(array_values(array_unique($publicIds)))->map(fn (string $id) => $this->findCustomer($id));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\BorrowerGroupStatus;
use App\Enums\BorrowerType;
use App\Enums\CollateralStatus;
use App\Enums\LoanActivityType;
use App\Enums\LoanStatus;
use App\Enums\PartyRole;
use App\Enums\ProductStatus;
use App\Enums\RepaymentFrequency;
use App\Exceptions\BusinessRuleException;
use App\Finance\Support\Money;
use App\Models\ApprovalRequest;
use App\Models\BorrowerGroup;
use App\Models\BorrowerGroupMember;
use App\Models\Collateral;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanParty;
use App\Models\LoanTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\Accounting\GeneralLedgerService;
use App\Support\BusinessDate;
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

            $special = $this->multipleOpenColumns($data, $user);

            return $type === BorrowerType::Group
                ? $this->createGroupFacility($product, $data, $tenure, $user, $special)
                : $this->createIndividualOrJoint($product, $type, $data, $tenure, $user, $special);
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

            // Once the application was submitted, a change needs a reason on the record. A change to the money terms
            // (amount, term, who borrows) also sends it back to be reviewed again: the reviewer, the approver and the
            // customer decided on the old figures.
            $reason = trim((string) ($data['remarks'] ?? ''));
            $stage = $loan->status;

            if (! $stage->changesNeedNoReview()) {
                if ($reason === '') {
                    throw new BusinessRuleException('EDIT_REASON_REQUIRED', 'Give the reason for changing an application that was already submitted.', 422);
                }

                // Paid out: the loan is in the books. Its details change; the money terms change through a restructure or a settlement.
                if ($stage->isPaidOut()) {
                    if ($this->changesMoneyTerms($loan, $data) || array_key_exists('allow_multiple_open', $data)) {
                        throw new BusinessRuleException('MONEY_TERMS_AFTER_PAYOUT', 'The amount, term and borrowers of a loan that was paid out change through a restructure or a settlement, not by editing it. The purpose, the custom fields and the application date can be edited.', 422);
                    }

                    $loan->fill(array_intersect_key($data, array_flip(['purpose', 'meta'])) + ['updated_by' => $user->id]);

                    if (isset($data['application_date'])) {
                        $loan->application_date = $data['application_date'];

                        if ($loan->isGroupFacility()) {
                            $loan->memberLoans()->update(['application_date' => $data['application_date']]);
                        }
                    }

                    $loan->save();
                    $this->workflow->record($loan, LoanActivityType::Update, $user, "Loan details changed while {$stage->value}: {$reason}", ['fields' => array_keys(array_diff_key($data, ['remarks' => 1])), 'stage' => $stage->value]);

                    return $loan->refresh();
                }

                if ($this->changesMoneyTerms($loan, $data)) {
                    $loan = $this->sendBackForReview($loan, $user, $stage, $reason);
                }
            }

            // First, so the checks below already see whether the application is a special case.
            if (array_key_exists('allow_multiple_open', $data)) {
                $this->applyMultipleOpen($loan, $data, $user);
            }

            // The date the customer applied: set again when he came back to finish the application.
            if (isset($data['application_date'])) {
                $loan->application_date = $data['application_date'];

                if ($loan->isGroupFacility()) {
                    $loan->memberLoans()->open()->update(['application_date' => $data['application_date']]);
                }
            }

            $terms = $this->pricing->termsOf($loan);
            $tenure = (int) ($data['tenure'] ?? $loan->tenure);
            $this->assertTenure($terms, $tenure);


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

            $this->workflow->record(
                $loan,
                LoanActivityType::Update,
                $user,
                $stage->changesNeedNoReview() ? 'Application updated' : "Application changed while {$stage->value}: {$reason}",
                ['fields' => array_keys(array_diff_key($data, ['remarks' => 1])), 'stage' => $stage->value],
            );

            return $loan->refresh();
        });
    }

    /**
     * Delete an application that was never paid out. The row is kept (soft delete); the people, security
     * and documents attached to it are removed so they no longer count against the customer's limits.
     * A loan that has a schedule, a payment or an active status is part of the books and can only be
     * settled, cancelled or written off.
     */
    public function delete(Loan $loan, string $reason, User $user): void
    {
        DB::transaction(function () use ($loan, $reason, $user) {
            $loan = $this->workflow->lock($loan);

            if ($loan->isMemberLoan()) {
                throw new BusinessRuleException('MEMBER_LOAN_ACTION', 'Delete the group facility; its member loans go with it.', 422);
            }

            $loans = $loan->isGroupFacility() ? $loan->memberLoans()->get()->prepend($loan) : collect([$loan]);

            $ids = $loan->idsWithMemberLoans();
            $paidOut = $loans->contains(fn (Loan $each) => $each->status->isPaidOut() || $each->installments()->exists());

            // A loan that was paid out is in the books: deleting it reverses its accounting entries, so it has its own permission.
            if ($paidOut && ! $user->can('Loan Delete After Payout')) {
                throw new BusinessRuleException('DELETE_AFTER_PAYOUT_NOT_ALLOWED', 'This loan was already paid out. Only someone with the permission "Loan Delete After Payout" can delete it.', 403);
            }

            foreach ($loans as $each) {
                // Collateral that is legally in place (mortgage registered, gold in the vault) is released first: by cancelling an application,
                // by settling or closing a loan. Deleting never leaves a pledged item without a visible record.
                if ($each->collaterals()->where('status', CollateralStatus::Perfected->value)->exists()) {
                    throw new BusinessRuleException('COLLATERAL_PERFECTED', $paidOut
                        ? 'The security of this loan is still in place (registered or in custody). Release it first (settle or close the loan), then delete it.'
                        : 'The security of this application is already in place (registered or in custody). Cancel the application first so it is released, then delete it.', 409);
                }
            }

            if ($paidOut) {
                $transactionIds = LoanTransaction::whereIn('loan_id', $ids)->pluck('id');

                $pending = ApprovalRequest::withoutGlobalScope('branch')->pending()->where(fn ($q) => $q
                    ->where(fn ($w) => $w->where('subject_type', (new Loan)->getMorphClass())->whereIn('subject_id', $ids))
                    ->orWhere(fn ($w) => $w->where('subject_type', (new LoanTransaction)->getMorphClass())->whereIn('subject_id', $transactionIds)))->exists();

                if ($pending) {
                    throw new BusinessRuleException('PENDING_APPROVALS', 'This loan has requests waiting in the approval inbox (a reversal, a waiver, a restructure, a write-off). Decide or withdraw them first.', 409);
                }
            }

            // One timestamp on the loan and on everything that goes with it: restore brings back exactly those rows.
            $at = now();

            $reversed = 0;

            if ($paidOut) {
                // Every journal the loan produced is mirrored to zero; the originals stay. The open recovery case is closed.
                $reversed = app(GeneralLedgerService::class)->voidLoanJournals($ids, $reason, $user);

                DB::table('recovery_cases')->whereIn('loan_id', $ids)->where('status', 'open')
                    ->update(['status' => 'closed', 'closed_at' => now(), 'open_case_key' => null, 'updated_at' => now()]);
            }

            foreach ($loans as $each) {
                $this->workflow->record($each, LoanActivityType::Update, $user, $paidOut ? "Loan deleted after payout ({$reversed} accounting entries reversed): {$reason}" : "Application deleted: {$reason}");

                // The borrowers let go of their open-loan key, so the customer can apply again.
                $each->parties()->update(['open_loan_key' => null, 'deleted_at' => $at]);
                $each->documents()->update(['deleted_at' => $at]);

                foreach ($each->collaterals()->get() as $collateral) {
                    $collateral->documents()->update(['deleted_at' => $at]);
                }

                $each->collaterals()->update(['deleted_at' => $at]);
                Loan::whereKey($each->id)->update(['updated_by' => $user->id, 'deleted_at' => $at]);
            }
        });
    }

    /**
     * Bring a deleted application back with its guarantors, collateral and documents, as it was.
     * The people on it are checked again: a customer who has opened another loan since, or a guarantor
     * who has reached the guarantee limit, stops the restore.
     */
    public function restore(Loan $loan, User $user): Loan
    {
        return DB::transaction(function () use ($loan, $user) {
            $loan = Loan::withTrashed()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if (! $loan->trashed()) {
                throw new BusinessRuleException('LOAN_NOT_DELETED', 'This application is not deleted.', 409);
            }

            if ($loan->isMemberLoan()) {
                throw new BusinessRuleException('MEMBER_LOAN_ACTION', 'Restore the group facility; its member loans come back with it.', 422);
            }

            $at = $loan->deleted_at->toDateTimeString();
            $loans = collect([$loan]);

            if ($loan->isGroupFacility()) {
                $loans = $loans->merge(Loan::onlyTrashed()->where('parent_id', $loan->id)->where('deleted_at', $at)->get());
            }

            // The guarantee limit and the guarantor's standing are checked as for a new guarantor.
            foreach ($loans as $each) {
                LoanParty::onlyTrashed()->with('customer')->where('loan_id', $each->id)->where('deleted_at', $at)
                    ->where('role', PartyRole::Guarantor->value)->whereNull('released_at')->get()
                    ->each(fn (LoanParty $party) => $this->eligibility->assertNone($this->eligibility->guarantorProblems($party->customer, $each->id), $party->customer));
            }

            // The one-open-loan rule applies to what is brought back, unless it was a special case.
            foreach ($loans->reject(fn (Loan $each) => $each->multiple_open_at !== null || $each->status->isClosed()) as $each) {
                LoanParty::onlyTrashed()->with('customer')->where('loan_id', $each->id)->where('deleted_at', $at)
                    ->whereIn('role', PartyRole::borrowingValues())->get()
                    ->each(function (LoanParty $party) use ($each) {
                        if ($open = $this->eligibility->openLoanOf($party->customer, [$each->id])) {
                            throw new BusinessRuleException('BORROWER_HAS_OPEN_LOAN', "{$party->customer->full_name} ({$party->customer->customer_no}) already has an open loan or application: {$open->application_no}.", 409, ['loan' => ['public_id' => $open->public_id, 'application_no' => $open->application_no, 'status' => $open->status->value]]);
                        }
                    });
            }

            $restoredPaidOut = $loans->contains(fn (Loan $each) => $each->status->isPaidOut() || $each->installments()->exists());

            foreach ($loans as $each) {
                $each->documents()->onlyTrashed()->where('deleted_at', $at)->restore();

                foreach (Collateral::onlyTrashed()->where('loan_id', $each->id)->where('deleted_at', $at)->get() as $collateral) {
                    $collateral->documents()->onlyTrashed()->where('deleted_at', $at)->restore();
                    $collateral->restore();
                }

                LoanParty::onlyTrashed()->where('loan_id', $each->id)->where('deleted_at', $at)->restore();
                $each->restore();
            }

            // A loan that was paid out is back in the books: the mirror of its void journals puts the original entries back.
            if ($restoredPaidOut) {
                app(GeneralLedgerService::class)->restoreLoanJournals($loan->idsWithMemberLoans(), $user);
            }

            // A returned application held its borrowers' open-loan key; the unique index refuses a second open loan.
            $this->workflow->reclaimOpenLoanKeys($loans);

            foreach ($loans as $each) {
                $this->workflow->record($each, LoanActivityType::Update, $user, 'Application restored');
            }

            $loan->forceFill(['updated_by' => $user->id])->save();

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
                        $this->eligibility->borrowerProblems($party->customer, $loan->tenure, RepaymentFrequency::from($terms['repayment_frequency']), true, $ignore, $each->multiple_open_at !== null),
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
     * @param  array<string, mixed>  $special  The special-case columns (see multipleOpenColumns), empty for an ordinary application.
     */
    protected function createIndividualOrJoint(Product $product, BorrowerType $type, array $data, int $tenure, User $user, array $special = []): Loan
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
            $this->eligibility->assertNone($this->eligibility->borrowerProblems($customer, $tenure, $product->repayment_frequency, false, [], $special !== []), $customer);
        }

        $loan = $this->newLoan($product, [
            'branch_id' => $borrower->branch_id,
            'borrower_type' => $type,
            'customer_id' => $borrower->id,
            'requested_amount' => $data['requested_amount'],
            'tenure' => $tenure,
            'application_date' => $data['application_date'] ?? BusinessDate::todayImmutable()->toDateString(),
            'purpose' => $data['purpose'] ?? null,
            'meta' => $data['meta'] ?? null,
        ], $user);

        $this->markSpecialCase($loan, $special, $user);

        $loan->parties()->create(['customer_id' => $borrower->id, 'role' => PartyRole::Borrower]);

        foreach ($coBorrowers as $coBorrower) {
            $loan->parties()->create(['customer_id' => $coBorrower->id, 'role' => PartyRole::CoBorrower]);
        }

        $this->logActivity('CREATE', 'Loan', "Created application {$loan->application_no}");

        return $loan->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $special
     */
    protected function createGroupFacility(Product $product, array $data, int $tenure, User $user, array $special = []): Loan
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
            // The facility row is inserted before its member loans exist, and the amount check needs > 0.
            'requested_amount' => (string) Money::sum(array_column($data['members'], 'amount')),
            'tenure' => $tenure,
            'application_date' => $data['application_date'] ?? BusinessDate::todayImmutable()->toDateString(),
            'purpose' => $data['purpose'] ?? null,
            'meta' => $data['meta'] ?? null,
        ], $user);

        $this->markSpecialCase($facility, $special, $user);
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
                $this->eligibility->borrowerProblems($row['customer'], $facility->tenure, RepaymentFrequency::from($terms['repayment_frequency']), false, [], $facility->multiple_open_at !== null),
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
                'application_date' => $facility->application_date?->toDateString(),
                'purpose' => $facility->purpose,
            ], $user);

            $memberLoan->forceFill([
                'status' => $facility->status,
                'multiple_open_by' => $facility->multiple_open_by,
                'multiple_open_reason' => $facility->multiple_open_reason,
                'multiple_open_at' => $facility->multiple_open_at,
            ])->save();
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

        $loan->parties()->where('role', PartyRole::CoBorrower->value)->whereNotIn('customer_id', $customers->pluck('id'))->forceDelete();

        foreach ($customers as $customer) {
            if ($loan->parties()->where('customer_id', $customer->id)->exists()) {
                continue;
            }

            $this->eligibility->assertNone($this->eligibility->borrowerProblems($customer, $loan->tenure, $loan->repayment_frequency, false, [$loan->id], $loan->multiple_open_at !== null), $customer);
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
    public function assertTenure(array $terms, int $tenure): void
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
            throw new BusinessRuleException('LOAN_NOT_EDITABLE', 'This loan cannot be edited.', 409);
        }
    }

    /**
     * Would this edit change what the reviewer, the approver or the customer decided on? Amount, term, and who borrows
     * count; the purpose, the custom fields and the application date do not. The same figures sent again change nothing.
     *
     * @param  array<string, mixed>  $data
     */
    protected function changesMoneyTerms(Loan $loan, array $data): bool
    {
        if (isset($data['tenure']) && (int) $data['tenure'] !== (int) $loan->tenure) {
            return true;
        }

        if (isset($data['requested_amount']) && ! Money::of($data['requested_amount'])->isEqualTo(Money::of((string) $loan->requested_amount))) {
            return true;
        }

        if (array_key_exists('co_borrower_public_ids', $data)) {
            $now = $loan->parties()->where('role', PartyRole::CoBorrower->value)->with('customer:id,public_id')->get()->pluck('customer.public_id')->sort()->values()->all();

            if ($now !== collect($data['co_borrower_public_ids'])->sort()->values()->all()) {
                return true;
            }
        }

        if (array_key_exists('members', $data)) {
            $now = $loan->memberLoans()->open()->with('customer:id,public_id')->get()
                ->mapWithKeys(fn (Loan $member) => [$member->customer->public_id => (string) Money::of((string) $member->requested_amount)])->sortKeys()->all();
            $wanted = collect($data['members'])->mapWithKeys(fn (array $row) => [$row['customer_public_id'] => (string) Money::of($row['amount'])])->sortKeys()->all();

            if ($now !== $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send an application that is past submission back to "returned": the approval, the offer acceptance and the
     * guarantors' consent were given on the old figures and are cleared by the workflow, so it goes through review,
     * verification and approval again with the new ones (members of a group move with their facility).
     */
    protected function sendBackForReview(Loan $loan, User $user, LoanStatus $stage, string $reason): Loan
    {
        // The workflow also voids the approval, the offer acceptance and the consents when it was already decided.
        return $this->workflow->transition($loan, LoanStatus::Returned, $user, "Money terms changed while {$stage->value}; sent back for review. Reason: {$reason}")->refresh();
    }

    /**
     * The special case: a customer may have more than one open loan. Only for someone holding
     * "Loan Multiple Open Allow", with a written reason. Returns the columns that record it, or nothing.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function multipleOpenColumns(array $data, User $user): array
    {
        if (! ($data['allow_multiple_open'] ?? false)) {
            return [];
        }

        if (! $user->can('Loan Multiple Open Allow')) {
            throw new BusinessRuleException('MULTIPLE_OPEN_NOT_ALLOWED', 'Only someone with the permission "Loan Multiple Open Allow" can give a customer more than one open loan.', 403);
        }

        return ['multiple_open_by' => $user->id, 'multiple_open_reason' => $data['multiple_open_reason'], 'multiple_open_at' => now()];
    }

    /**
     * @param  array<string, mixed>  $special
     */
    protected function markSpecialCase(Loan $loan, array $special, User $user): void
    {
        if ($special === []) {
            return;
        }

        $loan->forceFill($special)->save();
        $this->workflow->record($loan, LoanActivityType::Update, $user, "Special case: more than one open loan allowed. Reason: {$special['multiple_open_reason']}");
        $this->logActivity('SPECIAL_OPEN_LOAN', 'Loan', "Allowed more than one open loan for {$loan->application_no}", ['reason' => $special['multiple_open_reason']]);
    }

    /**
     * Turn the special case on or off for an application that can still be edited.
     * Turning it off makes the ordinary rule apply again, so it is refused while the customer has another open loan.
     *
     * @param  array<string, mixed>  $data
     */
    protected function applyMultipleOpen(Loan $loan, array $data, User $user): void
    {
        if ($data['allow_multiple_open'] ?? false) {
            $special = $this->multipleOpenColumns($data, $user);

            $loan->forceFill($special)->save();

            if ($loan->isGroupFacility()) {
                $loan->memberLoans()->open()->update($special);
            }

            $this->markSpecialCase($loan, $special, $user);

            return;
        }

        if ($loan->multiple_open_at === null) {
            return;
        }

        $off = ['multiple_open_by' => null, 'multiple_open_reason' => null, 'multiple_open_at' => null];
        $loan->forceFill($off)->save();

        if ($loan->isGroupFacility()) {
            $loan->memberLoans()->open()->update($off);
        }

        foreach ($this->borrowingLoans($loan) as $each) {
            foreach ($each->borrowingParties()->with('customer')->get() as $party) {
                $this->eligibility->assertNone(
                    $this->eligibility->borrowerProblems($party->customer, $loan->tenure, $loan->repayment_frequency, false, $loan->idsWithMemberLoans()),
                    $party->customer,
                );
            }
        }

        $this->workflow->record($loan, LoanActivityType::Update, $user, 'Special case withdrawn: the ordinary one-open-loan rule applies');
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

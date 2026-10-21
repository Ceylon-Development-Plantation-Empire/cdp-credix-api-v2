<?php

namespace App\Services\Loans;

use App\Enums\CollateralStatus;
use App\Enums\CollateralType;
use App\Enums\DocumentStatus;
use App\Enums\LoanActivityType;
use App\Enums\LoanStatus;
use App\Exceptions\BusinessRuleException;
use App\Finance\Collateral\GoldValuation;
use App\Finance\Support\Money;
use App\Models\Collateral;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanParty;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Collateral on a loan: valuation and LTV, verification by a second person, perfection
 * (the security legally in place) before disbursement.
 *
 * eligible amount = value × LTV of the product's rule for that collateral type
 * gold value      = net weight × karat / 24 × 24K rate per gram (never typed in by hand)
 */
class CollateralService
{
    /**
     * Collateral can be added or removed until the application is verified.
     */
    protected const CHANGE_WINDOW = [LoanStatus::Draft, LoanStatus::Submitted, LoanStatus::InReview, LoanStatus::Returned];

    public function __construct(
        protected LoanWorkflowService $workflow,
        protected LoanPricingService $pricing,
        protected GoldValuation $gold,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated CollateralRequest data.
     */
    public function add(Loan $loan, array $data, User $by): Collateral
    {
        return DB::transaction(function () use ($loan, $data, $by) {
            $loan = $this->workflow->lock($loan);
            $this->assertChangeWindow($loan);

            $type = CollateralType::from($data['type']);
            $rule = collect($this->pricing->termsOf($loan)['collateral_rules'])->firstWhere('collateral_type', $type->value);

            if (! $rule) {
                throw new BusinessRuleException('COLLATERAL_TYPE_NOT_ALLOWED', "{$type->label()} is not accepted as security for this product.", 422);
            }

            $ownerId = null;

            if (! empty($data['owner_customer_public_id'])) {
                $owner = Customer::where('public_id', $data['owner_customer_public_id'])->first();

                // The owner must be on the loan, so the pledge is backed by someone who signs for it.
                if (! $owner || ! LoanParty::whereIn('loan_id', $loan->idsWithMemberLoans())->where('customer_id', $owner->id)->exists()) {
                    throw new BusinessRuleException('COLLATERAL_OWNER_NOT_PARTY', 'The owner of the collateral must be a borrower or guarantor on this loan.', 422);
                }

                $ownerId = $owner->id;
            }

            $details = Arr::only($data['details'], array_keys($type->detailRules()));
            $value = $type->isValuedByWeight()
                ? $this->gold->value($details['net_weight_g'], (int) $details['karat'], $details['rate_per_gram_24k'])
                : Money::of($data['market_value']);

            $collateral = new Collateral([
                'loan_id' => $loan->id,
                'type' => $type,
                'owner_customer_id' => $ownerId,
                'description' => $data['description'],
                'details' => $details,
                'market_value' => (string) $value,
                'forced_sale_value' => $data['forced_sale_value'] ?? null,
                'valued_on' => $data['valued_on'],
                'valued_by' => $data['valued_by'] ?? null,
                'created_by' => $by->id,
            ]);

            $collateral->forceFill([
                'ltv_percent' => $rule['max_ltv_percent'],
                'eligible_amount' => (string) $this->gold->eligibleAmount($value, $rule['max_ltv_percent']),
            ])->save();

            $this->workflow->record($loan, LoanActivityType::Update, $by, "Collateral added: {$type->label()} ({$collateral->public_id})");

            return $collateral;
        });
    }

    /**
     * A second person checks the valuation and the documents that prove the security.
     */
    public function verify(Collateral $collateral, User $by): Collateral
    {
        return DB::transaction(function () use ($collateral, $by) {
            $collateral = Collateral::whereKey($collateral->id)->lockForUpdate()->firstOrFail();

            if ($collateral->status !== CollateralStatus::Proposed) {
                throw new BusinessRuleException('COLLATERAL_NOT_PROPOSED', 'Only a proposed collateral can be verified.', 409);
            }

            if ($collateral->created_by === $by->id) {
                throw new BusinessRuleException('SELF_VERIFICATION_NOT_ALLOWED', 'Collateral must be verified by someone other than the person who recorded it.', 403);
            }

            $missing = $this->missingDocuments($collateral);

            if ($missing) {
                throw new BusinessRuleException('COLLATERAL_DOCUMENTS_MISSING', 'Verified documents are missing for this collateral.', 422, ['missing' => $missing]);
            }

            $collateral->forceFill([
                'status' => CollateralStatus::Verified,
                'verified_by' => $by->id,
                'verified_at' => now(),
            ])->save();

            $this->workflow->record($collateral->loan, LoanActivityType::DocumentCheck, $by, "Collateral verified ({$collateral->public_id})");

            return $collateral;
        });
    }

    /**
     * The security is legally in place: mortgage bond registered, CR endorsed, gold sealed in the
     * vault. Done after approval, before disbursement (rule L-7).
     *
     * @param  array{registration_ref?: string|null, custody_ref?: string|null}  $data
     */
    public function perfect(Collateral $collateral, array $data, User $by): Collateral
    {
        return DB::transaction(function () use ($collateral, $data, $by) {
            $collateral = Collateral::whereKey($collateral->id)->lockForUpdate()->firstOrFail();
            $loan = $collateral->loan;

            if (! in_array($loan->status, [LoanStatus::Approved, LoanStatus::Accepted], true)) {
                throw new BusinessRuleException('COLLATERAL_PERFECTION_TOO_EARLY', 'Collateral is perfected after the loan is approved and before it is disbursed.', 409);
            }

            if ($collateral->status !== CollateralStatus::Verified) {
                throw new BusinessRuleException('COLLATERAL_NOT_VERIFIED', 'Only a verified collateral can be perfected.', 409);
            }

            $needsCustody = $collateral->type === CollateralType::Gold;
            $reference = $needsCustody ? ($data['custody_ref'] ?? null) : ($data['registration_ref'] ?? null);

            if (blank($reference)) {
                throw new BusinessRuleException('COLLATERAL_REFERENCE_REQUIRED', $needsCustody
                    ? 'The gold packet number and vault location (custody_ref) are required.'
                    : 'The registration reference (mortgage bond number / CR endorsement) is required.', 422);
            }

            $collateral->forceFill([
                $needsCustody ? 'custody_ref' : 'registration_ref' => $reference,
                'status' => CollateralStatus::Perfected,
                'perfected_by' => $by->id,
                'perfected_at' => now(),
            ])->save();

            $this->workflow->record($loan, LoanActivityType::DocumentCheck, $by, "Collateral perfected ({$collateral->public_id})");

            return $collateral;
        });
    }

    public function remove(Collateral $collateral, User $by): void
    {
        DB::transaction(function () use ($collateral, $by) {
            $loan = $this->workflow->lock($collateral->loan);
            $this->assertChangeWindow($loan);

            if ($collateral->documents()->where('status', DocumentStatus::Verified->value)->exists()) {
                throw new BusinessRuleException('COLLATERAL_HAS_VERIFIED_DOCUMENTS', 'This collateral has verified documents and cannot be removed.', 409);
            }

            $collateral->documents()->delete();
            $collateral->delete();

            $this->workflow->record($loan, LoanActivityType::Update, $by, "Collateral removed ({$collateral->public_id})");
        });
    }

    /**
     * Sum of what the verified (or perfected) collateral can secure.
     */
    public function coverage(Loan $loan): BigDecimal
    {
        return Money::sum($loan->collaterals()
            ->whereIn('status', [CollateralStatus::Verified->value, CollateralStatus::Perfected->value])
            ->pluck('eligible_amount'));
    }

    /**
     * @return list<string> slugs of document types still missing
     */
    public function missingDocuments(Collateral $collateral): array
    {
        $verified = $collateral->documents()->where('status', DocumentStatus::Verified->value)
            ->with('documentType')->get()->pluck('documentType.slug')->unique();

        return array_values(array_diff($collateral->type->requiredDocuments(), $verified->all()));
    }

    protected function assertChangeWindow(Loan $loan): void
    {
        if ($loan->isMemberLoan()) {
            throw new BusinessRuleException('MEMBER_LOAN_ACTION', 'Collateral of a group loan is recorded on the group facility.', 422);
        }

        if (! in_array($loan->status, self::CHANGE_WINDOW, true)) {
            throw new BusinessRuleException('COLLATERAL_LOCKED', 'Collateral can no longer change: the application has been verified.', 409);
        }
    }
}

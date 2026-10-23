<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\CollateralStatus;
use App\Enums\CollateralValuation;
use App\Enums\DocumentStatus;
use App\Enums\LoanActivityType;
use App\Enums\LoanStatus;
use App\Enums\PartyRole;
use App\Exceptions\BusinessRuleException;
use App\Finance\Collateral\GoldValuation;
use App\Finance\Support\Money;
use App\Models\Collateral;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanParty;
use App\Models\Setting;
use App\Support\BusinessDate;
use App\Support\Mask;
use App\Models\User;
use App\Support\Collateral\CollateralTypes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Collateral on a loan: valuation and LTV, verification by a second person, perfection
 * (the security legally in place) before disbursement.
 *
 * eligible amount = value × the product's lending % for the type when the product has one (shown for
 *                   information; nothing is refused because of it), else the full value
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
        protected CollateralTypes $types,
        protected InvestmentCollateralService $investments,
    ) {}

    /**
     * A CDP Core investment as collateral. The officer gives the NIC and the policy number; the value
     * and the details come from CDP Core (see InvestmentCollateralService for the rules), and Core's
     * answer is kept on the collateral as a snapshot.
     */
    public function addCdpInvestment(Loan $loan, string $nic, string $policyNumber, User $by): Collateral
    {
        return DB::transaction(function () use ($loan, $nic, $policyNumber, $by) {
            $loan = $this->workflow->lock($loan);
            $this->assertChangeWindow($loan);

            $borrowers = Customer::whereIn('id', LoanParty::whereIn('loan_id', $loan->idsWithMemberLoans())
                ->whereIn('role', PartyRole::borrowingValues())->whereNull('released_at')->pluck('customer_id'))->get();

            ['investment' => $investment, 'owner' => $owner] = $this->investments->validate($loan, $borrowers, $nic, $policyNumber);

            $collateral = $this->add($loan, [
                'type' => InvestmentCollateralService::TYPE,
                'owner_customer_public_id' => $owner->public_id,
                'description' => "CDP investment {$investment['policy_number']}".($investment['product_name'] ? " ({$investment['product_name']})" : ''),
                'details' => ['account_no' => $investment['policy_number'], 'institution' => 'CDP', 'maturity_date' => $investment['maturity_date']],
                'market_value' => $investment['investment_amount'],
                'valued_on' => BusinessDate::today()->toDateString(),
                'valued_by' => 'CDP Core',
            ], $by);

            // The fields add() keeps are the type's own; the policy and Core's snapshot ride along for the reviewers.
            $collateral->forceFill(['details' => ($collateral->details ?? []) + [
                'policy_number' => $investment['policy_number'],
                'nic' => Mask::idNumber($owner->normalizedIdNumber()),
                'investment' => $investment,
            ]])->save();

            return $collateral;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validated CollateralRequest data.
     */
    public function add(Loan $loan, array $data, User $by): Collateral
    {
        return DB::transaction(function () use ($loan, $data, $by) {
            $loan = $this->workflow->lock($loan);
            $this->assertChangeWindow($loan);

            $type = (string) $data['type'];
            $label = $this->types->label($type);
            $rule = collect($this->pricing->termsOf($loan)['collateral_rules'])->firstWhere('collateral_type', $type);

            // Security is recorded as data: no lending limit is applied and no kind of security is refused.
            // The product's % (when it has one for this type) is only copied on for information.
            $percent = $rule ? (string) $rule['max_ltv_percent'] : '100.00';

            $ownerId = null;

            if (! empty($data['owner_customer_public_id'])) {
                $owner = Customer::where('public_id', $data['owner_customer_public_id'])->first();

                // The owner must be on the loan, so the pledge is backed by someone who signs for it.
                if (! $owner || ! LoanParty::whereIn('loan_id', $loan->idsWithMemberLoans())->where('customer_id', $owner->id)->exists()) {
                    throw new BusinessRuleException('COLLATERAL_OWNER_NOT_PARTY', 'The owner of the collateral must be a borrower or guarantor on this loan.', 422);
                }

                $ownerId = $owner->id;
            }

            $details = Arr::only($data['details'] ?? [], array_keys($this->types->fieldRules($type)));
            $value = $this->types->valuation($type) === CollateralValuation::GoldWeight
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
                'ltv_percent' => $percent,
                'eligible_amount' => (string) $this->gold->eligibleAmount($value, $percent),
            ])->save();

            $this->workflow->record($loan, LoanActivityType::Update, $by, "Collateral added: {$label} ({$collateral->public_id}), value {$value}");

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

            $kind = $this->types->reference($collateral->type);
            $reference = $data[$kind->column()] ?? null;

            if (blank($reference)) {
                throw new BusinessRuleException('COLLATERAL_REFERENCE_REQUIRED', $kind->missingMessage(), 422);
            }

            $collateral->forceFill([
                $kind->column() => $reference,
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
     * @return list<string> slugs of document types still missing
     */
    public function missingDocuments(Collateral $collateral): array
    {
        $verified = $collateral->documents()->where('status', DocumentStatus::Verified->value)
            ->with('documentType')->get()->pluck('documentType.slug')->unique();

        return array_values(array_diff($this->types->documents($collateral->type), $verified->all()));
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

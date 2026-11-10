<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\CollateralStatus;
use App\Enums\LookupType;
use App\Exceptions\BusinessRuleException;
use App\Models\Collateral;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Product;
use App\Services\Customers\CdpConnectService;
use App\Support\BusinessDate;
use App\Support\Lookups\LookupRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A CDP Core investment policy pledged as collateral on a loan (collateral type cdp_investment).
 *
 * The officer gives the NIC and the policy number. Everything else comes from CDP Core, live: the
 * value of the collateral is what Core reports, never a figure typed into the screen, and Core's
 * answer is kept on the collateral as a snapshot for the reviewers.
 *
 * Rules for a CDP investment security (one policy secures one live loan at a time):
 *   1. a policy number is given
 *   2. the NIC belongs to a borrower on the loan (primary, co-borrower or group member)
 *   3. the policy is approved in Core under that NIC (not pending, cancelled, terminated, expired)
 *   4. no other open loan is already secured by it
 *   5. the policy matures on or after the loan's end date
 *
 * "Held" is derived from the collateral and the loan's status: CDP Core asks it through
 * GET /external/core/policy-hold before paying a policy out. Nothing else is stored.
 *
 * DECISION: no lending limit is applied to the amount (security is data, owner decision 22 Oct 2026);
 * the picker shows what the product's lending % for this type would allow, for information only.
 */
class InvestmentCollateralService
{
    public const TYPE = 'cdp_investment';

    public function __construct(
        protected CdpConnectService $cdp,
        protected LookupRegistry $lookups,
    ) {}

    /**
     * The customer's approved policies as Core reports them, with whether a live loan already holds each.
     * This is what the investment picker shows; nothing is stored.
     *
     * @return array{customer: mixed, summary: mixed, investments: list<array<string, mixed>>}
     */
    public function investmentsFor(Customer $customer, ?string $maxLoanPercent = null): array
    {
        $number = $customer->normalizedIdNumber() ?? trim((string) $customer->id_number);

        if ($number === '') {
            throw new BusinessRuleException('CUSTOMER_HAS_NO_ID_NUMBER', 'This customer has no ID number on record, so their CDP Core investments cannot be looked up.', 422);
        }

        return $this->approved($number, $this->cdpIdType($customer), null, $maxLoanPercent);
    }

    /**
     * One policy, by the NIC and policy number typed into the security step: what the screen shows
     * before anything is saved. Given the borrowers, rule 2 is checked here too.
     *
     * @param  iterable<int, Customer>  $borrowers
     * @return array{customer: mixed, investment: array<string, mixed>}
     */
    public function lookup(string $nic, string $policyNumber, iterable $borrowers = [], ?int $exceptLoanId = null, ?string $maxLoanPercent = null): array
    {
        $policy = self::normalisePolicy($policyNumber);
        $borrowers = collect($borrowers)->filter()->values();
        $owner = $borrowers->isNotEmpty() ? $this->ownerAmong($borrowers, $nic) : null;

        $result = $this->approved($this->normalizeNic($nic), $owner ? $this->cdpIdType($owner) : 'nic', $exceptLoanId, $maxLoanPercent);
        $investment = collect($result['investments'])->firstWhere('policy_number', $policy);

        if (! $investment) {
            throw new BusinessRuleException('POLICY_NOT_FOUND', "Policy {$policy} was not found among the approved CDP Core investments for NIC ".trim($nic).'.', 422);
        }

        return ['customer' => $result['customer'], 'investment' => $investment];
    }

    /**
     * Check a CDP investment against the rules above for a loan and return the investment as Core
     * reports it, with the borrower who owns it.
     *
     * @param  iterable<int, Customer>  $borrowers  everyone borrowing on the loan
     * @return array{investment: array<string, mixed>, owner: Customer}
     */
    public function validate(Loan $loan, iterable $borrowers, ?string $nic, ?string $policyNumber): array
    {
        $policy = self::normalisePolicy($policyNumber);

        if ($policy === '') {
            throw new BusinessRuleException('POLICY_NUMBER_REQUIRED', 'Enter the investment policy number that secures this loan.', 422);
        }

        $owner = $this->ownerAmong(collect($borrowers)->filter()->values(), (string) $nic);

        // Core is asked for this NIC's approved policies, so anything not in the list is not theirs or not approved.
        $investment = collect($this->approved($this->normalizeNic((string) $nic), $this->cdpIdType($owner), $loan->id)['investments'])->firstWhere('policy_number', $policy);

        if (! $investment) {
            throw new BusinessRuleException('POLICY_NOT_FOUND', "Policy {$policy} was not found among {$owner->full_name}'s approved investments in CDP Core.", 422);
        }

        if ($investment['in_use']) {
            throw new BusinessRuleException('POLICY_ALREADY_HELD', "Policy {$policy} already secures loan {$investment['in_use_by']}. An investment can back only one open loan at a time, until that loan is closed, rejected or cancelled.", 409);
        }

        if (! empty($investment['maturity_date'])) {
            $start = BusinessDate::todayImmutable();
            $loanEnds = $start->addMonths((int) $loan->tenure);
            $matures = CarbonImmutable::parse($investment['maturity_date'], BusinessDate::timezone())->startOfDay();

            if ($matures->lt($loanEnds)) {
                throw new BusinessRuleException('POLICY_MATURES_TOO_EARLY', sprintf(
                    'Policy %s matures on %s, before the loan would end on %s. Shorten the term to %d months or pledge a longer investment.',
                    $policy, $matures->toDateString(), $loanEnds->toDateString(), max(1, (int) $start->diffInMonths($matures)),
                ), 422);
            }
        }

        return ['investment' => $investment, 'owner' => $owner];
    }

    /**
     * The open loan whose collateral is this policy, if any (the hold CDP Core asks about).
     */
    public function holder(string $policy, ?int $exceptLoanId = null): ?Collateral
    {
        return $this->holding($policy)
            ->when($exceptLoanId, fn ($q) => $q->where('loan_id', '!=', $exceptLoanId))
            ->with('loan')
            ->first();
    }

    /**
     * Collateral still securing an open loan, across every branch.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Collateral>
     */
    public function holding(?string $policy = null)
    {
        return Collateral::withoutGlobalScopes()
            ->where('collateral_type_id', $this->lookups->idFor(LookupType::CollateralType, self::TYPE))
            ->whereIn('status', [CollateralStatus::Proposed->value, CollateralStatus::Verified->value, CollateralStatus::Perfected->value])
            ->when($policy !== null, fn ($q) => $q->where('details->policy_number', self::normalisePolicy($policy)))
            ->whereHas('loan', fn ($q) => $q->withoutGlobalScopes()->open());
    }

    /**
     * The product's lending % for this kind of security, when it has one (for information in the picker).
     */
    public function maxLoanPercentFor(?string $productSlug): ?string
    {
        $rule = $productSlug ? Product::findBySlug($productSlug)?->collateralRules->firstWhere('collateral_type', self::TYPE) : null;

        return $rule ? (string) $rule->max_ltv_percent : null;
    }

    /** Trimmed and upper-cased: the one form policy numbers are compared in. */
    public static function normalisePolicy(?string $policyNumber): string
    {
        return strtoupper(trim((string) $policyNumber));
    }

    /**
     * One shape for a CDP Core investment record (an item of /external/customer-investments).
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function normalise(array $raw): array
    {
        // Core sends the plan as `product`; `investment_product` is the older name.
        $product = $raw['product'] ?? $raw['investment_product'] ?? null;
        $product = is_array($product) ? $product : [];

        $startedAt = $raw['reservation_date'] ?? null;
        $months = (int) ($product['duration_months'] ?? 0);

        // Core's own maturity date when it sends one; otherwise start + duration.
        $maturity = null;
        try {
            if (! empty($raw['maturity_date'])) {
                $maturity = CarbonImmutable::parse($raw['maturity_date'])->toDateString();
            } elseif ($startedAt && $months > 0) {
                $maturity = CarbonImmutable::parse($startedAt)->addMonths($months)->toDateString();
            }
        } catch (\Throwable) {
            $maturity = null;
        }

        $status = strtolower(trim((string) ($raw['status'] ?? '')));
        $eligible = $status === 'approved'
            && empty($raw['is_expired'])
            && empty($raw['cancelled_at'])
            && empty($raw['terminated_at'])
            && empty($raw['rejected_at'])
            && empty($raw['deleted_at']);

        return [
            'policy_number' => self::normalisePolicy($raw['policy_number'] ?? ''),
            'application_number' => $raw['application_number'] ?? null,
            'investment_amount' => number_format((float) ($raw['investment_amount'] ?? 0), 2, '.', ''),
            'product_name' => $product['name'] ?? null,
            'product_code' => $product['code'] ?? null,
            'roi_percentage' => isset($product['roi_percentage']) ? (float) $product['roi_percentage'] : null,
            'duration_months' => $months ?: null,
            'started_at' => $startedAt ? CarbonImmutable::parse($startedAt)->toDateString() : null,
            'maturity_date' => $maturity,
            'status' => $status,
            'eligible' => $eligible,
        ];
    }

    /**
     * Rule 2: the NIC is compared in its normalised form, so the old (853400937V) and new
     * (198534000937) formats of the same NIC match.
     *
     * @param  Collection<int, Customer>  $borrowers
     */
    protected function ownerAmong(Collection $borrowers, string $nic): Customer
    {
        $typed = $this->normalizeNic($nic);

        $owner = $typed === '' ? null : $borrowers->first(fn (Customer $borrower) => $borrower->normalizedIdNumber() === $typed);

        if ($owner) {
            return $owner;
        }

        throw new BusinessRuleException('NIC_NOT_A_BORROWER', $borrowers->count() === 1
            ? sprintf('NIC %s is not the NIC of %s. A CDP investment can only secure a loan its owner is borrowing on.', trim($nic), $borrowers->first()->full_name)
            : sprintf('NIC %s does not belong to any borrower on this loan. A CDP investment can only secure a loan its owner is borrowing on.', trim($nic)), 422);
    }

    /**
     * The approved, pledgeable policies Core holds under one ID number, each with whether an open
     * loan already holds it (and, when the product's lending % is known, the most a loan could be).
     *
     * @return array{customer: mixed, summary: mixed, investments: list<array<string, mixed>>}
     */
    protected function approved(string $idNumber, ?string $idType, ?int $exceptLoanId, ?string $maxLoanPercent = null): array
    {
        try {
            $payload = $this->cdp->customerInvestments($idNumber, $idType, 'approved');
        } catch (BusinessRuleException $e) {
            // Core's own 401 means our key was refused; passed through, the screen would read it as the officer's
            // session having ended. Not found is a clean "nothing to pledge".
            throw match ($e->errorCode) {
                'CDP_CUSTOMER_NOT_FOUND' => new BusinessRuleException('NO_CDP_INVESTMENTS', 'No CDP Core investments were found for '.trim($idNumber).'.', 422),
                default => $e,
            };
        }

        $investments = collect($payload['investments'])
            ->map(fn ($raw) => self::normalise((array) $raw))
            ->filter(fn (array $investment) => $investment['eligible'] && $investment['policy_number'] !== '')
            ->map(function (array $investment) use ($exceptLoanId, $maxLoanPercent) {
                $holder = $this->holder($investment['policy_number'], $exceptLoanId);

                return $investment + [
                    'max_loan' => $maxLoanPercent !== null ? number_format((float) $investment['investment_amount'] * (float) $maxLoanPercent / 100, 2, '.', '') : null,
                    'in_use' => $holder !== null,
                    'in_use_by' => $holder?->loan?->application_no,
                ];
            })
            ->values()
            ->all();

        return ['customer' => $payload['customer'], 'summary' => $payload['summary'], 'investments' => $investments];
    }

    protected function normalizeNic(string $nic): string
    {
        return (string) (\App\Enums\IdType::Nic->normalize($nic) ?? strtoupper(preg_replace('/[\s-]+/', '', $nic)));
    }

    protected function cdpIdType(Customer $customer): string
    {
        return match ($customer->id_type?->value) {
            'nic' => 'nic',
            'passport' => 'passport',
            'driving_licence' => 'driving_license',
            default => 'other',
        };
    }
}

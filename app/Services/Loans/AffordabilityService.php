<?php

namespace App\Services\Loans;

use App\Enums\BorrowerType;
use App\Enums\FinancialKind;
use App\Finance\Affordability\DebtServiceRatio;
use App\Finance\Support\Money;
use App\Models\CustomerFinancial;
use App\Models\Loan;
use App\Models\Setting;
use Brick\Math\BigDecimal;

/**
 * Debt service ratio of an application (design section 6.6), from the borrowers' recorded
 * income, expenses and existing loan installments. A joint loan adds up every borrower.
 *
 * At submission all recorded income is used, to show the officer where the application stands.
 * At verification and approval only VERIFIED income counts, while unverified expenses and
 * obligations still count: an unchecked figure can only make the result more cautious.
 *
 * Group member loans and bullet (gold) loans are not assessed on DSR: group loans are small and
 * rely on the group, gold loans on the gold.
 */
class AffordabilityService
{
    public function __construct(
        protected DebtServiceRatio $dsr,
    ) {}

    public function applies(Loan $loan): bool
    {
        return $loan->borrower_type !== BorrowerType::Group && ! $loan->pricing_method->isBullet();
    }

    /**
     * Writes the assessment columns on the loan (not saved).
     */
    public function assess(Loan $loan, LoanPricing $pricing, bool $verifiedIncomeOnly): void
    {
        if (! $this->applies($loan)) {
            $loan->forceFill([
                'assessed_monthly_income' => null,
                'assessed_monthly_expenses' => null,
                'existing_obligations' => null,
                'dsr_percent' => null,
            ]);

            return;
        }

        $financials = CustomerFinancial::query()
            ->whereIn('customer_id', $loan->borrowingParties()->pluck('customer_id'))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', today()))
            ->get();

        $income = Money::sum($financials
            ->filter(fn (CustomerFinancial $f) => $f->kind === FinancialKind::Income && (! $verifiedIncomeOnly || $f->is_verified))
            ->pluck('amount'));
        $expenses = Money::sum($financials->where('kind', FinancialKind::Expense)->pluck('amount'));
        $obligations = Money::sum($financials->where('kind', FinancialKind::Liability)->pluck('monthly_obligation')->filter());

        $netIncome = $income->minus($expenses);
        $monthlyInstallment = $pricing->monthlyInstallment($loan->repayment_frequency->periodsPerYear());

        $loan->forceFill([
            'assessed_monthly_income' => (string) $income,
            'assessed_monthly_expenses' => (string) $expenses,
            'existing_obligations' => (string) $obligations,
            // No positive net income means the loan cannot be serviced at all; the DSR stays empty.
            'dsr_percent' => $netIncome->isGreaterThan(0) ? (string) $this->dsr->percent($netIncome, $obligations, $monthlyInstallment) : null,
        ]);
    }

    /**
     * The highest DSR the product accepts.
     *
     * @param  array<string, mixed>  $terms
     */
    public function maxPercent(array $terms): BigDecimal
    {
        return BigDecimal::of($terms['max_dsr_percent'] ?? Setting::value('affordability.max_dsr_percent', 60));
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    public function isAffordable(Loan $loan, array $terms): bool
    {
        if (! $this->applies($loan)) {
            return true;
        }

        return $loan->dsr_percent !== null && $this->dsr->isAffordable(BigDecimal::of($loan->dsr_percent), $this->maxPercent($terms));
    }
}

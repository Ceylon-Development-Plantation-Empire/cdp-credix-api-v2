<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\BorrowerType;
use App\Enums\FinancialKind;
use App\Finance\Support\Money;
use App\Models\CustomerFinancial;
use App\Models\Loan;
use App\Support\BusinessDate;
use Brick\Math\BigDecimal;

/**
 * Monthly income, expenses and obligations assessment of an application,
 * from the borrowers' recorded financials.
 */
class AffordabilityService
{
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
            ]);

            return;
        }

        $financials = CustomerFinancial::query()
            ->whereIn('customer_id', $loan->borrowingParties()->pluck('customer_id'))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', BusinessDate::today()))
            ->get();

        $income = Money::sum($financials
            ->filter(fn (CustomerFinancial $f) => $f->kind === FinancialKind::Income && (! $verifiedIncomeOnly || $f->is_verified))
            ->pluck('amount'));
        $expenses = Money::sum($financials->where('kind', FinancialKind::Expense)->pluck('amount'));
        $obligations = Money::sum($financials->where('kind', FinancialKind::Liability)->pluck('monthly_obligation')->filter());

        $loan->forceFill([
            'assessed_monthly_income' => (string) $income,
            'assessed_monthly_expenses' => (string) $expenses,
            'existing_obligations' => (string) $obligations,
        ]);
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    public function isAffordable(Loan $loan, array $terms): bool
    {
        // Approving official decides affordability discretionarily based on documents and income sources.
        return true;
    }
}

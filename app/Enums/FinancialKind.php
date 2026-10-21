<?php

namespace App\Enums;

enum FinancialKind: string
{
    case Income = 'income';
    case Expense = 'expense';
    case Asset = 'asset';
    case Liability = 'liability';

    /**
     * Allowed categories per kind, so reports and the affordability calculation can group them.
     *
     * @return list<string>
     */
    public function categories(): array
    {
        return match ($this) {
            self::Income => ['salary', 'allowance', 'business_income', 'rental_income', 'agriculture', 'remittance', 'pension', 'other'],
            self::Expense => ['household', 'rent', 'education', 'utilities', 'transport', 'medical', 'other'],
            self::Asset => ['land', 'house', 'vehicle', 'machinery', 'shares', 'savings', 'gold', 'other'],
            self::Liability => ['bank_loan', 'leasing', 'credit_card', 'microfinance', 'pawning', 'informal', 'other'],
        };
    }
}

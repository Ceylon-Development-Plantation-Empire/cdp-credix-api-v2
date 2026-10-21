<?php

namespace App\Enums;

enum GlAccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    /**
     * Assets and expenses grow with debits; liabilities, equity and income grow with credits.
     */
    public function isDebitNormal(): bool
    {
        return in_array($this, [self::Asset, self::Expense], true);
    }
}

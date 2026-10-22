<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a customer_financials line is. The kind is code (the affordability calculation treats each
 * kind differently); the categories within a kind are business data (lookups
 * financial_category.<kind>, see LookupType::forFinancialKind()).
 */
enum FinancialKind: string
{
    // Monthly income (salary, business income …): counts towards repayment capacity.
    case Income = 'income';
    // Monthly household expense: reduces repayment capacity.
    case Expense = 'expense';
    // Something the customer owns (value): context for the officer, never used as income.
    case Asset = 'asset';
    // An existing debt (outstanding balance + monthly installment): the installment counts in the DSR.
    case Liability = 'liability';
}

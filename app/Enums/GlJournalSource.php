<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What created a journal. Only manual journals are typed in by a person (and need approval);
 * the others are posted by the posting rules.
 */
enum GlJournalSource: string
{
    // A loan ledger transaction: disbursement, payment, late charge, waiver, reversal …
    case LoanTransaction = 'loan_transaction';

    // Interest / profit of an installment becomes income on its due date (nightly run).
    case InstallmentDue = 'installment_due';

    // A new schedule: profit already recognised is taken back; capitalised interest becomes principal.
    case Restructure = 'restructure';

    // A non-performing loan's recognised interest moved to suspense, or released back to income.
    case IncomeSuspension = 'income_suspension';

    // Monthly top-up or release of the provision for loan losses.
    case Provision = 'provision';

    // A loan deleted after it was paid out: every journal it produced is mirrored back to zero (and mirrored again when it is restored).
    case LoanVoid = 'loan_void';

    case LoanVoidRestore = 'loan_void_restore';

    case Manual = 'manual';
}

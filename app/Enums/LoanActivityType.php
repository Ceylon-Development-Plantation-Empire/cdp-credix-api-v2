<?php

declare(strict_types=1);

namespace App\Enums;

enum LoanActivityType: string
{
    case StatusChange = 'status_change';
    case Note = 'note';
    case Call = 'call';
    case Visit = 'visit';
    case PromiseToPay = 'promise_to_pay';
    case DocumentCheck = 'document_check';

    // Parties, collateral and consents changing on an application.
    case Update = 'update';

    // A customer asks, on the review link, for a detail to be corrected; an officer answers it.
    case CorrectionRequest = 'correction_request';
    case CorrectionResolved = 'correction_resolved';
}

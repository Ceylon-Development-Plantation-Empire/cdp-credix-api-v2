<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The role a customer plays on a loan (loan_parties.role) and in document requirements.
 */
enum PartyRole: string
{
    case Borrower = 'borrower';
    case CoBorrower = 'co_borrower';
    case GroupMember = 'group_member';
    case Guarantor = 'guarantor';

    /**
     * Roles that owe the loan. These count for "one open loan per person" (L-1) and must
     * accept the offer by OTP; a guarantor only consents to guarantee.
     */
    public function isBorrowing(): bool
    {
        return $this !== self::Guarantor;
    }

    /**
     * @return list<string>
     */
    public static function borrowingValues(): array
    {
        return [self::Borrower->value, self::CoBorrower->value, self::GroupMember->value];
    }
}

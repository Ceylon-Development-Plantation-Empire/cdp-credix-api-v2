<?php

namespace App\Enums;

enum UserType: string
{
    case Admin = 'admin';
    case Staff = 'staff';

    // External recovery agency; sees only the recovery cases assigned to it.
    case Agency = 'agency';

    /**
     * Back-office users. Staff routes are closed to every other type, so an agency token can
     * never reach an internal endpoint even when no permission is set on it. Customers never
     * log in: the system is for CDP staff only.
     *
     * @return list<string>
     */
    public static function backOffice(): array
    {
        return [self::Admin->value, self::Staff->value];
    }
}

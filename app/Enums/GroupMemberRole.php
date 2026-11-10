<?php

declare(strict_types=1);

namespace App\Enums;

enum GroupMemberRole: string
{
    case Leader = 'leader';
    case Secretary = 'secretary';
    case Treasurer = 'treasurer';
    case Member = 'member';

    /**
     * Office bearer roles can be held by only one current member at a time.
     */
    public function isOfficeBearer(): bool
    {
        return $this !== self::Member;
    }
}

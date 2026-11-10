<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserType;
use App\Models\User;

/**
 * Which branch's data a user may see.
 *
 * - admin users and users with the "Branch Data All" permission (head office) see every branch;
 * - every other back-office user sees only the branch of their employee record;
 * - a staff user without an employee branch sees nothing (safe default, never "everything");
 * - console commands and queue jobs (no logged-in user) are not restricted.
 */
final class BranchAccess
{
    public const ALL_BRANCHES_PERMISSION = 'Branch Data All';

    /**
     * Null = no restriction. Otherwise the only branch id the user may access (0 = none).
     */
    public static function restrictedBranchId(?User $user = null): ?int
    {
        $user ??= auth('api')->user();

        if (! $user || $user->user_type === UserType::Admin->value) {
            return null;
        }

        if ($user->can(self::ALL_BRANCHES_PERMISSION)) {
            return null;
        }

        return (int) ($user->employee?->branch_id ?? 0);
    }

    public static function canAccessBranch(?int $branchId, ?User $user = null): bool
    {
        $restricted = self::restrictedBranchId($user);

        return $restricted === null || $restricted === $branchId;
    }
}

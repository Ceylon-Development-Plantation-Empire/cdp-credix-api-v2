<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserType;
use App\Models\User;

/**
 * Which branch's data a user may see.
 *
 * - admin users and users with the "Branch Data All" permission (head office) see every branch;
 * - back-office staff see the branches they are assigned to (primary branch + secondary branches);
 * - a staff user without an assigned branch sees nothing (safe default, never "everything");
 * - console commands and queue jobs (no logged-in user) are not restricted.
 */
final class BranchAccess
{
    public const ALL_BRANCHES_PERMISSION = 'Branch Data All';

    /**
     * Null = no restriction (admin / head office permission).
     * Otherwise the list of branch IDs the user may access (empty array = none).
     *
     * @return list<int>|null
     */
    public static function restrictedBranchIds(?User $user = null): ?array
    {
        $user ??= auth('api')->user();

        if (! $user || $user->user_type === UserType::Admin->value) {
            return null;
        }

        if ($user->can(self::ALL_BRANCHES_PERMISSION)) {
            return null;
        }

        $employee = $user->employee;
        if (! $employee) {
            return [];
        }

        return $employee->allAccessibleBranchIds();
    }

    /**
     * Backward-compatible helper: Null = no restriction. Otherwise the primary branch id or 0.
     */
    public static function restrictedBranchId(?User $user = null): ?int
    {
        $restricted = self::restrictedBranchIds($user);

        if ($restricted === null) {
            return null;
        }

        return $restricted[0] ?? 0;
    }

    public static function canAccessBranch(?int $branchId, ?User $user = null): bool
    {
        $restricted = self::restrictedBranchIds($user);

        if ($restricted === null) {
            return true;
        }

        if ($branchId === null) {
            return false;
        }

        return in_array($branchId, $restricted, true);
    }
}

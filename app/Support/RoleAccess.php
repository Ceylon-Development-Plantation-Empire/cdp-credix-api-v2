<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * The ceiling on handing out access: nobody can give a role, or put a user in a role, that holds
 * a permission they do not hold themselves. Without it "Role Update" or "User Update" is a way
 * to become anything. A Super Admin has no ceiling.
 */
class RoleAccess
{
    public const SUPER_ADMIN = 'Super Admin';

    public static function isSuperAdmin(User $user): bool
    {
        return $user->hasRole(self::SUPER_ADMIN);
    }

    /**
     * The permission names in $wanted that $actor may not hand out.
     *
     * @param  iterable<string>  $wanted
     * @param  iterable<string>  $alreadyOnTarget  names the target holds now (keeping them is not granting them)
     * @return list<string>
     */
    public static function ungrantable(User $actor, iterable $wanted, iterable $alreadyOnTarget = []): array
    {
        if (self::isSuperAdmin($actor)) {
            return [];
        }

        $held = $actor->getAllPermissions()->pluck('name')->all();
        $keep = collect($alreadyOnTarget)->all();

        return collect($wanted)->reject(fn (string $name) => in_array($name, $held, true) || in_array($name, $keep, true))->values()->all();
    }

    /**
     * May $actor put someone into this role? Super Admin only by a Super Admin; any other role only
     * if every permission it holds is one the actor holds.
     */
    public static function canAssignRole(User $actor, string $roleName): bool
    {
        if (self::isSuperAdmin($actor)) {
            return true;
        }

        if ($roleName === self::SUPER_ADMIN) {
            return false;
        }

        $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();

        return $role !== null && self::ungrantable($actor, $role->permissions->pluck('name')) === [];
    }
}

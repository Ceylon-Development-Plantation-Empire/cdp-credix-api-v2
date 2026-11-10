<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserType;
use App\Models\User;

/**
 * Who may manage which user accounts (on top of the "User Create / Update / Delete / Toggle
 * Status" permissions checked by the controller middleware).
 *
 * SECURITY: admin accounts can only be created, changed or deactivated by a Super Admin, and
 * nobody can deactivate or delete their own account (no lock-out of the last administrator by
 * accident, no self-service privilege games).
 */
class UserPolicy
{
    /**
     * The role allowed to manage administrators.
     */
    public const SUPER_ADMIN = 'Super Admin';

    /**
     * Create a user of the given type.
     */
    public function create(User $actor, string $userType): bool
    {
        return $userType !== UserType::Admin->value || $actor->hasRole(self::SUPER_ADMIN);
    }

    /**
     * Change a user (and, if given, turn it into the given type).
     */
    public function update(User $actor, User $target, ?string $newType = null): bool
    {
        $touchesAdmin = $target->user_type === UserType::Admin->value || $newType === UserType::Admin->value;

        return ! $touchesAdmin || $actor->hasRole(self::SUPER_ADMIN);
    }

    /**
     * Delete a user (DELETE /users/{id}): the permission decides (middleware), never oneself, and an admin account only by a Super Admin.
     */
    public function delete(User $actor, User $target): bool
    {
        return $actor->id !== $target->id && $this->update($actor, $target);
    }

    /**
     * Switch a user active / inactive: never oneself; admins only by a Super Admin.
     */
    public function toggleStatus(User $actor, User $target): bool
    {
        return $actor->id !== $target->id && $this->update($actor, $target);
    }
}

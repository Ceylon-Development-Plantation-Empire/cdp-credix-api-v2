<?php

declare(strict_types=1);

namespace App\Services\Groups;

use App\Enums\BorrowerGroupStatus;
use App\Enums\GroupMemberRole;
use App\Enums\LoanStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\BorrowerGroup;
use App\Models\BorrowerGroupMember;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\User;
use App\Support\BranchAccess;
use App\Support\BusinessDate;
use App\Traits\ActivityLogTrait;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Borrower groups and their members (design rules GR-1 … GR-7).
 */
class BorrowerGroupService
{
    use ActivityLogTrait;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user): BorrowerGroup
    {
        if (! BranchAccess::canAccessBranch((int) $data['branch_id'], $user)) {
            throw new BusinessRuleException('BRANCH_NOT_ALLOWED', 'You can only create groups in your own branch.', 403);
        }

        return BorrowerGroup::create($data + ['created_by' => $user->id, 'updated_by' => $user->id])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BorrowerGroup $group, array $data, User $user): BorrowerGroup
    {
        return DB::transaction(function () use ($group, $data, $user) {
            $group = BorrowerGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();

            if (($data['status'] ?? null) === BorrowerGroupStatus::Dissolved->value && $group->status !== BorrowerGroupStatus::Dissolved) {
                $this->dissolve($group);
            }

            $group->update($data + ['updated_by' => $user->id]);

            return $group;
        });
    }

    public function addMember(BorrowerGroup $group, Customer $customer, GroupMemberRole $role, User $user): BorrowerGroupMember
    {
        return DB::transaction(function () use ($group, $customer, $role, $user) {
            // Lock the group so two people adding members at once see the same member list.
            $group = BorrowerGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();

            if ($group->status !== BorrowerGroupStatus::Active) {
                throw new BusinessRuleException('GROUP_NOT_ACTIVE', 'Members can only be added to an active group.');
            }

            $this->assertMembershipCanChange($group);

            if ($customer->branch_id !== $group->branch_id) {
                throw new BusinessRuleException('GROUP_BRANCH_MISMATCH', 'The customer belongs to a different branch from the group.', 422);
            }

            if (! $customer->is_active) {
                throw new BusinessRuleException('CUSTOMER_INACTIVE', 'An inactive customer cannot join a group.');
            }

            // GR-6: one current group per person. The unique index enforces it; this check gives a clear message.
            $current = BorrowerGroupMember::with('group')->where('customer_id', $customer->id)->whereNull('left_on')->first();

            if ($current) {
                throw new BusinessRuleException('MEMBER_IN_ANOTHER_GROUP', 'This customer is already a member of a group.', 409, [
                    'group_no' => $current->group?->group_no,
                ]);
            }

            $this->assertRoleFree($group, $role);

            try {
                $member = $group->members()->create([
                    'customer_id' => $customer->id,
                    'role' => $role,
                    'joined_on' => BusinessDate::today()->toDateString(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another request added the same person to a group a moment ago.
                throw new BusinessRuleException('MEMBER_IN_ANOTHER_GROUP', 'This customer is already a member of a group.', 409);
            }

            $this->logActivity('ADD_MEMBER', 'BorrowerGroup', "Added {$customer->customer_no} to {$group->group_no} as {$role->value}", ['by' => $user->id]);

            return $member;
        });
    }

    public function changeRole(BorrowerGroupMember $member, GroupMemberRole $role): BorrowerGroupMember
    {
        return DB::transaction(function () use ($member, $role) {
            $group = BorrowerGroup::whereKey($member->borrower_group_id)->lockForUpdate()->firstOrFail();

            if (! $member->isCurrent()) {
                throw new BusinessRuleException('MEMBER_LEFT', 'This person is no longer a member of the group.');
            }

            $this->assertRoleFree($group, $role, $member->id);
            $member->update(['role' => $role]);

            return $member;
        });
    }

    public function removeMember(BorrowerGroupMember $member, User $user): BorrowerGroupMember
    {
        return DB::transaction(function () use ($member, $user) {
            $group = BorrowerGroup::whereKey($member->borrower_group_id)->lockForUpdate()->firstOrFail();

            if (! $member->isCurrent()) {
                throw new BusinessRuleException('MEMBER_LEFT', 'This person has already left the group.');
            }

            $this->assertMembershipCanChange($group);

            // The row stays as history; left_on frees the person to join another group.
            $member->update(['left_on' => BusinessDate::today()->toDateString()]);

            $this->logActivity('REMOVE_MEMBER', 'BorrowerGroup', "Member left {$group->group_no}", ['member_id' => $member->id, 'by' => $user->id]);

            return $member;
        });
    }

    /**
     * GR-1 / GR-2 / GR-3: membership is frozen while the group has an active loan (approved,
     * accepted or disbursed and not closed). It is open again once that loan is closed, and it is
     * open while an application is waiting for approval (members added then can be put on the
     * application before it is approved).
     */
    public function assertMembershipCanChange(BorrowerGroup $group): void
    {
        if ($this->hasActiveLoan($group)) {
            throw new BusinessRuleException('GROUP_HAS_ACTIVE_LOAN', 'Members cannot change while the group has an active loan.');
        }
    }

    public function hasActiveLoan(BorrowerGroup $group): bool
    {
        return Loan::query()
            ->withoutGlobalScope('branch')
            ->topLevel()
            ->where('borrower_group_id', $group->id)
            ->whereIn('status', array_map(fn (LoanStatus $status) => $status->value, LoanStatus::groupFreezing()))
            ->exists();
    }

    /**
     * Dissolving ends every current membership so members are free to join another group.
     */
    protected function dissolve(BorrowerGroup $group): void
    {
        $this->assertMembershipCanChange($group);

        $group->currentMembers()->update(['left_on' => BusinessDate::today()->toDateString()]);
    }

    /**
     * Leader, secretary and treasurer are held by one current member at a time.
     */
    protected function assertRoleFree(BorrowerGroup $group, GroupMemberRole $role, ?int $ignoreMemberId = null): void
    {
        if (! $role->isOfficeBearer()) {
            return;
        }

        $taken = $group->currentMembers()
            ->where('role', $role->value)
            ->when($ignoreMemberId, fn ($q, $id) => $q->whereKeyNot($id))
            ->exists();

        if ($taken) {
            throw new BusinessRuleException('GROUP_ROLE_TAKEN', "The group already has a {$role->value}.", 409);
        }
    }
}

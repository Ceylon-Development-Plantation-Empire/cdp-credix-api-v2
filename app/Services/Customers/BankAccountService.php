<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use App\Traits\ActivityLogTrait;
use Illuminate\Support\Facades\DB;

class BankAccountService
{
    use ActivityLogTrait;

    /**
     * The first account of a customer always becomes the primary one (the account loans are paid into).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Customer $customer, array $data): CustomerBankAccount
    {
        return DB::transaction(function () use ($customer, $data) {
            $this->assertNotDuplicate($customer, $data['account_number']);

            $makePrimary = ($data['is_primary'] ?? false) || ! $customer->bankAccounts()->where('is_active', true)->exists();

            if ($makePrimary) {
                $customer->bankAccounts()->update(['is_primary' => false]);
            }

            $account = $customer->bankAccounts()->create(array_merge($data, ['is_primary' => $makePrimary]));

            // Never log the account number itself.
            $this->logActivity('CREATE', 'CustomerBankAccount', "Added bank account {$account->maskedNumber()} for {$customer->customer_no}");

            return $account;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CustomerBankAccount $account, array $data): CustomerBankAccount
    {
        return DB::transaction(function () use ($account, $data) {
            if (isset($data['account_number']) && $data['account_number'] !== $account->account_number) {
                $this->assertNotDuplicate($account->customer, $data['account_number'], $account->id);
            }

            if (($data['is_active'] ?? true) === false && $account->is_primary) {
                throw new BusinessRuleException('PRIMARY_ACCOUNT_REQUIRED', 'Make another account primary before deactivating this one.');
            }

            if ($data['is_primary'] ?? false) {
                $account->customer->bankAccounts()->whereKeyNot($account->id)->update(['is_primary' => false]);
            } elseif (array_key_exists('is_primary', $data) && $account->is_primary) {
                throw new BusinessRuleException('PRIMARY_ACCOUNT_REQUIRED', 'Choose another account as primary instead of removing the primary flag.');
            }

            $account->update($data);

            $this->logActivity('UPDATE', 'CustomerBankAccount', "Updated bank account {$account->maskedNumber()}");

            return $account;
        });
    }

    public function delete(CustomerBankAccount $account): void
    {
        if ($account->is_primary && $account->customer->bankAccounts()->whereKeyNot($account->id)->where('is_active', true)->exists()) {
            throw new BusinessRuleException('PRIMARY_ACCOUNT_REQUIRED', 'Make another account primary before removing this one.');
        }

        $account->delete();

        $this->logActivity('DELETE', 'CustomerBankAccount', "Removed bank account {$account->maskedNumber()}");
    }

    protected function assertNotDuplicate(Customer $customer, string $number, ?int $ignoreId = null): void
    {
        $exists = $customer->bankAccounts()
            ->where('account_hash', CustomerBankAccount::hashNumber($number))
            ->when($ignoreId, fn ($q, $id) => $q->whereKeyNot($id))
            ->exists();

        if ($exists) {
            throw new BusinessRuleException('BANK_ACCOUNT_EXISTS', 'This account is already registered for the customer.', 409);
        }
    }
}

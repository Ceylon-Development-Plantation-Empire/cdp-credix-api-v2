<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Blind index for encrypted columns: a keyed HMAC-SHA256 of the normalised value.
 *
 * SECURITY: the encrypted column cannot be searched or made unique, so a deterministic keyed hash
 * is stored beside it. Without the key the hash cannot be reversed by trying all NIC numbers (a
 * plain SHA-256 of a 12-digit NIC could be brute-forced in minutes). The key is the application
 * key; rotating APP_KEY therefore needs the hashes rebuilt (documented in the README).
 *
 * Used for customers.id_number_hash, employees.id_number_hash and customer_bank_accounts.account_hash.
 */
final class BlindIndex
{
    /**
     * Hash a value that has already been normalised by its caller.
     *
     * @param  string  $purpose  Domain separation ("customer-id", "employee-id", "bank-account"), so
     *                           the same number in two columns gives two unrelated hashes.
     */
    public static function hash(string $purpose, string $normalisedValue): string
    {
        return hash_hmac('sha256', $purpose.'|'.$normalisedValue, (string) config('app.key'));
    }
}

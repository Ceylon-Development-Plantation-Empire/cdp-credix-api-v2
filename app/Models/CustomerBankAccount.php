<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerBankAccount extends Model
{
    use HasPublicId, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'bank_name',
        'branch_name',
        'account_holder_name',
        'account_number',
        'is_primary',
        'is_active',
    ];

    protected $casts = [
        // Encrypted with APP_KEY before it is written; decrypted only when read in PHP.
        'account_number' => 'encrypted',
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'account_number',
        'account_hash',
    ];

    protected static function booted(): void
    {
        // Keep the display digits and the duplicate-check hash in step with the encrypted number.
        static::saving(function (CustomerBankAccount $account) {
            if ($account->isDirty('account_number')) {
                $account->account_last4 = substr($account->account_number, -4);
                $account->account_hash = self::hashNumber($account->account_number);
            }
        });
    }

    /**
     * HMAC with the app key: lets us find the same account number without decrypting every row,
     * and cannot be reversed by trying all numbers without the key.
     */
    public static function hashNumber(string $number): string
    {
        return hash_hmac('sha256', $number, (string) config('app.key'));
    }

    public function maskedNumber(): string
    {
        return '****'.$this->account_last4;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

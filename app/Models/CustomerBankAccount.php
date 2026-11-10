<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LookupType;
use App\Models\Concerns\Audited;
use App\Models\Concerns\HasPublicId;
use App\Support\BlindIndex;
use App\Support\Lookups\LookupRegistry;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A bank account a disbursement can be paid to.
 *
 * SECURITY: the account number is encrypted at rest; account_last4 (display) and account_hash
 * (blind index, duplicate check) are kept in step on every save.
 *
 * bank_name is the label of a "bank" lookup value, stored as bank_id.
 *
 * @property int $id
 * @property string $public_id
 * @property string|null $bank_name
 * @property string $account_number
 * @property string $account_last4
 */
class CustomerBankAccount extends Model
{
    use Audited;
    use HasPublicId, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'bank_name',
        'branch_name',
        'account_holder_name',
        'account_number',
        'is_primary',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'account_number',
        'account_hash',
        'bank_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // SECURITY: encrypted with APP_KEY before it is written; decrypted only when read in PHP.
            'account_number' => 'encrypted',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Keep the display digits and the duplicate-check hash in step with the encrypted number.
     */
    protected static function booted(): void
    {
        static::saving(function (CustomerBankAccount $account) {
            if ($account->isDirty('account_number')) {
                $account->account_last4 = substr($account->account_number, -4);
                $account->account_hash = self::hashNumber($account->account_number);
            }
        });
    }

    /**
     * Blind index of an account number (finds the same account without decrypting every row).
     */
    public static function hashNumber(string $number): string
    {
        return BlindIndex::hash('bank-account', $number);
    }

    /**
     * bank_name (lookup label) ↔ bank_id.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function bankName(): Attribute
    {
        return Attribute::make(
            get: fn () => app(LookupRegistry::class)->labelOf(LookupType::Bank, isset($this->attributes['bank_id']) ? (int) $this->attributes['bank_id'] : null),
            set: fn (?string $name) => ['bank_id' => app(LookupRegistry::class)->idForLabel(LookupType::Bank, $name, 'bank_name')],
        );
    }

    /**
     * The number as shown to users without the sensitive permission.
     */
    public function maskedNumber(): string
    {
        return '****'.$this->account_last4;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

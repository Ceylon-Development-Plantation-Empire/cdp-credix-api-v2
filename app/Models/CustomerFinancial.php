<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FinancialKind;
use App\Enums\LookupType;
use App\Models\Concerns\Audited;
use App\Support\Lookups\LookupRegistry;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * One income, expense, asset or liability line of a customer (affordability assessment).
 *
 * category is a code of the lookup list of the line's kind (financial_category.income …), stored
 * as category_id. Set kind before category when filling (validated request data has that order).
 *
 * @property int $id
 * @property FinancialKind $kind
 * @property string|null $category
 * @property string $amount
 */
class CustomerFinancial extends Model
{
    use Audited;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'kind',
        'category',
        'description',
        'institution_name',
        'reference_no',
        'amount',
        'monthly_obligation',
        'start_date',
        'end_date',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['category_id'];

    /**
     * @var list<string>
     */
    protected $appends = ['category'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => FinancialKind::class,
            'amount' => 'decimal:2',
            'monthly_obligation' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * category (code in the list of the line's kind) ↔ category_id.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function category(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->kind
                ? app(LookupRegistry::class)->codeOf(LookupType::forFinancialKind($this->kind), isset($this->attributes['category_id']) ? (int) $this->attributes['category_id'] : null)
                : null,
            set: function (?string $code) {
                if (! $this->kind instanceof FinancialKind) {
                    throw new LogicException('Set the kind of a financial line before its category.');
                }

                return ['category_id' => app(LookupRegistry::class)->idFor(LookupType::forFinancialKind($this->kind), $code, 'category')];
            },
        );
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}

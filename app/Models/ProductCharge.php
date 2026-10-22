<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChargeCalculation;
use App\Enums\ChargeCollection;
use App\Enums\ChargeRecurrence;
use App\Models\Concerns\Audited;
use App\Support\Charges\ChargeTypeRegistry;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * A fee or charge a product carries. charge_type is the code of a charge_types row (the API and the
 * loan terms snapshot speak codes), stored as charge_type_id.
 *
 * @property int $id
 * @property int $product_id
 * @property int $charge_type_id
 * @property string|null $charge_type
 * @property ChargeCalculation $calculation
 * @property string $value
 * @property ChargeCollection $collection
 * @property ChargeRecurrence $recurrence
 * @property bool $is_charity
 * @property bool $is_active
 */
class ProductCharge extends Model
{
    use Audited;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'charge_type',
        'calculation',
        'value',
        'min_amount',
        'max_amount',
        'collection',
        'recurrence',
        'is_charity',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['charge_type_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'calculation' => ChargeCalculation::class,
            'collection' => ChargeCollection::class,
            'recurrence' => ChargeRecurrence::class,
            'value' => 'decimal:4',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'is_charity' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * charge_type (code) ↔ charge_type_id.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function chargeType(): Attribute
    {
        return Attribute::make(
            get: fn () => app(ChargeTypeRegistry::class)->findById(isset($this->attributes['charge_type_id']) ? (int) $this->attributes['charge_type_id'] : null)?->code,
            set: fn (?string $code) => ['charge_type_id' => app(ChargeTypeRegistry::class)->find($code)?->id
                ?? throw ValidationException::withMessages(['charge_type' => 'The selected charge type is not valid.'])],
        );
    }

    /**
     * The charge type row (name, display group, behaviour).
     */
    public function type(): ChargeType
    {
        return app(ChargeTypeRegistry::class)->findById((int) $this->charge_type_id)
            ?? throw new \LogicException("Product charge {$this->id} points at a missing charge type.");
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

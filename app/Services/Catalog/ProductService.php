<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ApprovalAction;
use App\Enums\ChargeCollection;
use App\Enums\PricingMethod;
use App\Enums\ProductStatus;
use App\Enums\RepaymentFrequency;
use App\Exceptions\BusinessRuleException;
use App\Finance\Charges\ChargeCalculator;
use App\Finance\Schedule\LoanTerms;
use App\Finance\Support\Money;
use App\Models\ApprovalRequest;
use App\Models\ChargeType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Approvals\ApprovalRequestService;
use App\Support\BusinessDate;
use App\Support\Charges\ChargeTypeRegistry;
use App\Support\Collateral\CollateralTypes;
use BackedEnum;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProductService
{
    /**
     * Values used when a create request leaves these out.
     */
    protected const DEFAULTS = [
        'allows_individual' => true,
        'allows_joint' => false,
        'allows_group' => false,
        'min_guarantors' => 0,
        'max_guarantors' => 0,
        'requires_collateral' => false,
        'early_settlement_allowed' => true,
    ];

    protected const CHARGE_FIELDS = ['charge_type', 'calculation', 'value', 'min_amount', 'max_amount', 'collection', 'recurrence', 'is_charity', 'is_active'];

    protected const COLLATERAL_FIELDS = ['collateral_type', 'max_ltv_percent', 'is_mandatory'];

    public function __construct(
        protected ProductRulesValidator $rules,
        protected ApprovalRequestService $approvals,
        protected ChargeCalculator $chargeCalculator,
    ) {}

    /**
     * Create a product (always as a draft) with its charges and collateral rules.
     *
     * @param  array<string, mixed>  $data  Validated CreateProductRequest data.
     */
    public function create(array $data, User $user): Product
    {
        return DB::transaction(function () use ($data, $user) {
            $category = ProductCategory::findBySlug($data['category_slug']);
            $charges = $this->normalizeCharges($data['charges'] ?? []);
            $collateralRules = $this->normalizeCollateralRules($data['collateral_rules'] ?? []);

            $terms = array_merge(self::DEFAULTS, $this->productAttributes($data));
            $terms['counterpart_product_id'] = $this->resolveCounterpart($data['counterpart_product_slug'] ?? null, $category)?->id;

            $this->rules->validate($category->finance_basis, $terms, $charges, $collateralRules);

            $product = Product::create($terms + [
                'category_id' => $category->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $product->charges()->createMany($charges);
            $product->collateralRules()->createMany($collateralRules);

            return $product;
        });
    }

    /**
     * Update a product.
     *
     * Draft and conventional products change straight away. For an approved Islamic product the
     * Shariah terms (pricing method, rates, rebate …) are locked: those changes are packed into an
     * approval request and the live product keeps its approved terms until the board approves.
     * Commercial fields (name, limits, borrower types …) still change straight away.
     *
     * @param  array<string, mixed>  $data  Validated UpdateProductRequest data.
     */
    public function update(Product $product, array $data, User $user): ProductChangeResult
    {
        return DB::transaction(function () use ($product, $data, $user) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->assertNotUnderReview($product);

            $changes = $this->productAttributes($data);
            $category = $product->category;

            if (isset($data['category_slug']) && $data['category_slug'] !== $category->slug) {
                $category = $this->resolveCategoryChange($product, $data['category_slug']);
                $changes['category_id'] = $category->id;
            }

            if (array_key_exists('counterpart_product_slug', $data)) {
                $changes['counterpart_product_id'] = $this->resolveCounterpart($data['counterpart_product_slug'], $category, $product)?->id;
            }

            if (isset($changes['slug']) && $changes['slug'] !== $product->slug && ! $product->status->isEditable()) {
                throw new BusinessRuleException('PRODUCT_SLUG_LOCKED', 'The slug of a product that has gone live cannot change.', 422);
            }

            // Validate the product as it will look after the change, with its current charges and rules.
            $this->rules->validate(
                $category->finance_basis,
                array_merge($this->currentTerms($product), $changes),
                $this->currentCharges($product),
                $this->currentCollateralRules($product),
            );

            $shariahChanges = [];

            if ($product->hasLockedShariahTerms()) {
                $shariahChanges = $this->changedFields($product, Arr::only($changes, Product::SHARIAH_TERMS));
                $changes = Arr::except($changes, Product::SHARIAH_TERMS);
            }

            $product->update($changes + ['updated_by' => $user->id]);

            $approval = $shariahChanges
                ? $this->proposeTermsChange($product, ['product' => $shariahChanges], $data['change_reason'] ?? null, $user)
                : null;

            return new ProductChangeResult($product->fresh(['category', 'counterpart', 'charges', 'collateralRules']), $approval);
        });
    }

    /**
     * Replace the full list of charges of a product.
     *
     * @param  list<array<string, mixed>>  $charges
     */
    public function replaceCharges(Product $product, array $charges, ?string $reason, User $user): ProductChangeResult
    {
        return DB::transaction(function () use ($product, $charges, $reason, $user) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->assertNotUnderReview($product);

            $charges = $this->normalizeCharges($charges);
            $this->rules->validate($product->financeBasis(), $this->currentTerms($product), $charges, $this->currentCollateralRules($product));

            // Charges are part of what the Shariah board approves (late payment to charity, fixed fees …).
            if ($product->hasLockedShariahTerms()) {
                $approval = $this->proposeTermsChange($product, ['charges' => $charges], $reason, $user);

                return new ProductChangeResult($product->fresh(['charges']), $approval);
            }

            $this->writeCharges($product, $charges);
            $product->update(['updated_by' => $user->id]);

            return new ProductChangeResult($product->fresh(['charges']), null);
        });
    }

    /**
     * Replace the collateral rules. LTV is credit-risk policy, not a Shariah term, so it changes
     * directly for every product.
     *
     * @param  list<array<string, mixed>>  $collateralRules
     */
    public function replaceCollateralRules(Product $product, array $collateralRules, User $user): Product
    {
        return DB::transaction(function () use ($product, $collateralRules, $user) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            $collateralRules = $this->normalizeCollateralRules($collateralRules);
            $this->rules->validate($product->financeBasis(), $this->currentTerms($product), $this->currentCharges($product), $collateralRules);

            if ($product->status === ProductStatus::Active) {
                $this->rules->validateForActivation($this->currentTerms($product), $collateralRules);
            }

            $product->collateralRules()->delete();
            $product->collateralRules()->createMany($collateralRules);
            $product->update(['updated_by' => $user->id]);

            return $product->fresh(['collateralRules']);
        });
    }

    /**
     * Put a product on sale. Islamic products must have been approved by the Shariah board first.
     */
    public function activate(Product $product, User $user): Product
    {
        return DB::transaction(function () use ($product, $user) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($product->status === ProductStatus::Active) {
                throw new BusinessRuleException('PRODUCT_ALREADY_ACTIVE', 'The product is already active.');
            }

            $this->assertNotUnderReview($product);

            if ($product->requiresShariahApproval() && $product->shariah_approved_at === null) {
                throw new BusinessRuleException('SHARIAH_APPROVAL_REQUIRED', 'An Islamic product must be approved by the Shariah board before it can be activated. Submit it for Shariah review.');
            }

            $this->assertReadyToSell($product);

            $product->forceFill(['status' => ProductStatus::Active, 'updated_by' => $user->id])->save();

            return $product;
        });
    }

    public function deactivate(Product $product, User $user): Product
    {
        if ($product->status !== ProductStatus::Active) {
            throw new BusinessRuleException('PRODUCT_NOT_ACTIVE', 'Only an active product can be deactivated.');
        }

        $product->forceFill(['status' => ProductStatus::Inactive, 'updated_by' => $user->id])->save();

        return $product;
    }

    /**
     * Send a draft Islamic product to the Shariah board. The approval request carries a snapshot of
     * exactly what is being approved, so the decision can be traced back to those terms later.
     */
    public function submitForShariahReview(Product $product, string $reason, User $user): ApprovalRequest
    {
        return DB::transaction(function () use ($product, $reason, $user) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (! $product->requiresShariahApproval()) {
                throw new BusinessRuleException('NOT_AN_ISLAMIC_PRODUCT', 'Only Islamic products go through Shariah review.', 422);
            }

            if ($product->status !== ProductStatus::Draft) {
                throw new BusinessRuleException('PRODUCT_NOT_DRAFT', 'Only a draft product can be submitted for Shariah review.');
            }

            $this->assertReadyToSell($product);

            $product->forceFill(['status' => ProductStatus::PendingShariah, 'updated_by' => $user->id])->save();

            return $this->approvals->create($product, ApprovalAction::ProductShariahApproval, $this->snapshot($product), $reason, $user);
        });
    }

    /**
     * Only a draft that has never been approved can be deleted. Anything that went live stays
     * (deactivate it instead) because loans and audit history refer to it.
     */
    public function delete(Product $product): void
    {
        if ($product->status !== ProductStatus::Draft || $product->shariah_approved_at !== null) {
            throw new BusinessRuleException('PRODUCT_NOT_DELETABLE', 'Only a draft product that never went live can be deleted. Deactivate it instead.');
        }

        $product->delete();
    }

    /**
     * Price a loan on a product without saving anything: schedule, upfront charges and net payout.
     * Used by the product screen and the loan wizard so the customer sees the exact numbers.
     *
     * @param  array<string, mixed>  $input  Validated QuoteProductRequest data.
     * @return array<string, mixed>
     */
    public function quote(Product $product, array $input): array
    {
        $amount = Money::of($input['amount']);
        $tenure = (int) $input['tenure'];
        $rate = BigDecimal::of($input['rate'] ?? $product->default_rate);

        $errors = [];

        if ($amount->isLessThan($product->min_amount) || $amount->isGreaterThan($product->max_amount)) {
            $errors[] = "Amount must be between {$product->min_amount} and {$product->max_amount}.";
        }

        if ($tenure < $product->min_tenure || $tenure > $product->max_tenure) {
            $errors[] = "Tenure must be between {$product->min_tenure} and {$product->max_tenure}.";
        }

        if ($rate->isLessThan($product->min_rate) || $rate->isGreaterThan($product->max_rate)) {
            $errors[] = "Rate must be between {$product->min_rate} and {$product->max_rate}.";
        }

        if ($errors) {
            throw new BusinessRuleException('QUOTE_OUT_OF_PRODUCT_LIMITS', 'The quote is outside the product limits.', 422, ['errors' => $errors]);
        }

        $residual = $product->residual_value_percent ? Money::percentOf($amount, $product->residual_value_percent) : null;

        $terms = new LoanTerms(
            principal: $amount,
            annualRate: $rate,
            tenure: $tenure,
            frequency: $product->repayment_frequency,
            startDate: CarbonImmutable::parse($input['start_date'] ?? BusinessDate::today()->toDateString()),
            firstDueDate: isset($input['first_due_date']) ? CarbonImmutable::parse($input['first_due_date']) : null,
            residualValue: $residual,
        );

        $schedule = $product->pricing_method->calculator()->buildSchedule($terms);
        [$upfront, $deducted] = $this->upfrontCharges($product, $amount);

        return [
            'product' => ['slug' => $product->slug, 'name' => $product->name, 'status' => $product->status->value],
            'finance_basis' => $product->financeBasis()->value,
            'cost_label' => $product->financeBasis()->costLabel(),
            'pricing_method' => $product->pricing_method->value,
            'amount' => (string) $amount,
            'rate' => (string) $rate,
            'tenure' => $tenure,
            'repayment_frequency' => $product->repayment_frequency->value,
            'upfront_charges' => $upfront,
            // Additive: one line per display group ("Service charges") with its itemised breakdown.
            'charge_breakdown' => $this->chargeBreakdown($upfront),
            'net_disbursement_amount' => (string) $amount->minus($deducted),
            'schedule' => $schedule->toArray(),
        ];
    }

    /**
     * Charges collected at the start, and the part of them taken out of the payout.
     *
     * Each line carries the charge type's name and display group (additive fields), so the quote can
     * show one combined line per group with the breakdown underneath.
     *
     * @return array{0: list<array{charge_type: string, name: string, display_group: string, collection: string, amount: string}>, 1: BigDecimal}
     */
    protected function upfrontCharges(Product $product, BigDecimal $amount): array
    {
        $lines = [];
        $deducted = Money::zero();

        foreach ($product->charges()->where('is_active', true)->get() as $charge) {
            $type = $charge->type();

            if ($type->behaviour->isEventDriven()) {
                continue;
            }

            $value = $this->chargeCalculator->amount($charge->calculation, $charge->value, $amount, $charge->min_amount, $charge->max_amount);

            if ($charge->collection === ChargeCollection::DeductFromDisbursement) {
                $deducted = $deducted->plus($value);
            }

            $lines[] = [
                'charge_type' => $type->code,
                'name' => $type->name,
                'display_group' => $type->display_group,
                'collection' => $charge->collection->value,
                'amount' => (string) $value,
            ];
        }

        return [$lines, $deducted];
    }

    /**
     * Upfront charge lines grouped by display group: [{group, total, items: [{charge_type, name, amount}]}].
     *
     * @param  list<array{charge_type: string, name: string, display_group: string, collection: string, amount: string}>  $lines
     * @return list<array{group: string, total: string, items: list<array{charge_type: string, name: string, amount: string}>}>
     */
    protected function chargeBreakdown(array $lines): array
    {
        $groups = [];

        foreach ($lines as $line) {
            $groups[$line['display_group']] ??= ['group' => $line['display_group'], 'total' => Money::zero(), 'items' => []];
            $groups[$line['display_group']]['total'] = $groups[$line['display_group']]['total']->plus($line['amount']);
            $groups[$line['display_group']]['items'][] = ['charge_type' => $line['charge_type'], 'name' => $line['name'], 'amount' => $line['amount']];
        }

        return array_values(array_map(fn (array $g) => [...$g, 'total' => (string) $g['total']], $groups));
    }

    /**
     * Create the approval request for a change to locked Shariah terms (one at a time per product).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function proposeTermsChange(Product $product, array $payload, ?string $reason, User $user): ApprovalRequest
    {
        if (blank($reason)) {
            throw new BusinessRuleException('CHANGE_REASON_REQUIRED', 'A change reason is required because this change needs Shariah approval.', 422);
        }

        if ($product->pendingApproval()) {
            throw new BusinessRuleException('APPROVAL_ALREADY_PENDING', 'This product already has a change waiting for Shariah approval.');
        }

        return $this->approvals->create($product, ApprovalAction::ProductTermsChange, $payload, $reason, $user);
    }

    /**
     * Apply an approved terms change. Called by ProductTermsChangeHandler.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyApprovedTermsChange(Product $product, array $payload, string $reference): void
    {
        $terms = array_merge($this->currentTerms($product), $payload['product'] ?? []);
        $charges = isset($payload['charges']) ? $this->normalizeCharges($payload['charges']) : $this->currentCharges($product);

        // The product may have changed since the request was made, so check the result again.
        $this->rules->validate($product->financeBasis(), $terms, $charges, $this->currentCollateralRules($product));

        if (isset($payload['product'])) {
            $product->update($payload['product']);
        }

        if (isset($payload['charges'])) {
            $this->writeCharges($product, $charges);
        }

        $product->forceFill(['shariah_approved_at' => now(), 'shariah_reference' => $reference])->save();
    }

    protected function assertReadyToSell(Product $product): void
    {
        if (! $product->category->is_active) {
            throw new BusinessRuleException('CATEGORY_INACTIVE', 'The product category is inactive.');
        }

        $terms = $this->currentTerms($product);
        $collateralRules = $this->currentCollateralRules($product);

        $this->rules->validate($product->financeBasis(), $terms, $this->currentCharges($product), $collateralRules);
        $this->rules->validateForActivation($terms, $collateralRules);
    }

    protected function assertNotUnderReview(Product $product): void
    {
        if ($product->status === ProductStatus::PendingShariah) {
            throw new BusinessRuleException('PRODUCT_UNDER_SHARIAH_REVIEW', 'The product is under Shariah review and cannot be changed until the board decides.');
        }
    }

    /**
     * A product can change category freely while it is a draft. Once it has been live it can only
     * move within the same basis, because loans were priced under that basis.
     */
    protected function resolveCategoryChange(Product $product, string $slug): ProductCategory
    {
        $category = ProductCategory::findBySlug($slug);

        if (! $product->status->isEditable() && $category->finance_basis !== $product->financeBasis()) {
            throw new BusinessRuleException('PRODUCT_BASIS_LOCKED', 'A product that has gone live cannot move between General and Islamic.', 422);
        }

        return $category;
    }

    /**
     * The counterpart is the same product in the other basis, so it must be in the other basis.
     */
    protected function resolveCounterpart(?string $slug, ProductCategory $category, ?Product $self = null): ?Product
    {
        if ($slug === null) {
            return null;
        }

        $counterpart = Product::with('category')->where('slug', $slug)->first();

        if ($self && $counterpart->id === $self->id) {
            throw new BusinessRuleException('COUNTERPART_INVALID', 'A product cannot be its own counterpart.', 422);
        }

        if ($counterpart->category->finance_basis === $category->finance_basis) {
            throw new BusinessRuleException('COUNTERPART_INVALID', 'The counterpart must be a product of the other finance basis (General ↔ Islamic).', 422);
        }

        return $counterpart;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function productAttributes(array $data): array
    {
        return Arr::except($data, ['category_slug', 'counterpart_product_slug', 'charges', 'collateral_rules', 'change_reason']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function currentTerms(Product $product): array
    {
        return $product->only((new Product)->getFillable());
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function currentCharges(Product $product): array
    {
        return $product->charges()->get()->map->only(self::CHARGE_FIELDS)->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function currentCollateralRules(Product $product): array
    {
        return $product->collateralRules()->get()->map->only(self::COLLATERAL_FIELDS)->all();
    }

    /**
     * @param  list<array<string, mixed>>  $charges
     */
    protected function writeCharges(Product $product, array $charges): void
    {
        $product->charges()->delete();
        $product->charges()->createMany($charges);
    }

    /**
     * @param  list<array<string, mixed>>  $charges
     * @return list<array<string, mixed>>
     */
    protected function normalizeCharges(array $charges): array
    {
        return array_map(fn (array $charge) => array_merge(
            ['min_amount' => null, 'max_amount' => null, 'recurrence' => 'once', 'is_charity' => false, 'is_active' => true],
            Arr::only($charge, self::CHARGE_FIELDS),
        ), array_values($charges));
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    protected function normalizeCollateralRules(array $rules): array
    {
        return array_map(fn (array $rule) => array_merge(['is_mandatory' => false], Arr::only($rule, self::COLLATERAL_FIELDS)), array_values($rules));
    }

    /**
     * Keep only the fields whose value really changes ("24" and "24.0000" are the same rate).
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    protected function changedFields(Product $product, array $changes): array
    {
        return array_filter($changes, function ($value, $field) use ($product) {
            $current = $product->{$field};
            $current = $current instanceof BackedEnum ? $current->value : $current;

            if (is_numeric($current) && is_numeric($value)) {
                return ! BigDecimal::of($current)->isEqualTo($value);
            }

            return $current != $value;
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Everything the Shariah board looks at, frozen at submission time.
     *
     * @return array<string, mixed>
     */
    protected function snapshot(Product $product): array
    {
        return [
            'product' => Arr::only($this->currentTerms($product), [...Product::SHARIAH_TERMS, 'name', 'min_amount', 'max_amount', 'min_tenure', 'max_tenure']),
            'category' => $product->category->slug,
            'charges' => $this->currentCharges($product),
        ];
    }

    /**
     * Dropdown data for the product form: which methods each basis may use and the options
     * each charge type allows, so the frontend only offers valid combinations.
     *
     * @return array<string, mixed>
     */
    public static function formOptions(): array
    {
        return [
            'pricing_methods' => array_map(fn (PricingMethod $method) => [
                'value' => $method->value,
                'label' => $method->label(),
                'finance_basis' => $method->basis()->value,
                'profit_free' => $method->isProfitFree(),
                'bullet' => $method->isBullet(),
                'residual_value' => $method->supportsResidualValue(),
            ], PricingMethod::cases()),
            'repayment_frequencies' => array_map(fn (RepaymentFrequency $f) => $f->value, RepaymentFrequency::cases()),
            // Same shape as before (value, calculations, collections, recurrences), now read from the
            // charge_types table; name / display_group / behaviour are additive.
            'charge_types' => app(ChargeTypeRegistry::class)->all()
                ->filter(fn (ChargeType $type) => $type->is_active)
                ->map(fn (ChargeType $type) => [
                    'value' => $type->code,
                    'name' => $type->name,
                    'display_group' => $type->display_group,
                    'behaviour' => $type->behaviour->value,
                    'calculations' => $type->allowed_calculations,
                    'collections' => array_map(fn ($c) => $c->value, $type->behaviour->allowedCollections()),
                    'recurrences' => $type->allowed_recurrences,
                ])->values()->all(),
            // Same shape as before (value, label), now read from the collateral_type lookup list.
            'collateral_types' => array_map(fn (array $type) => ['value' => $type['code'], 'label' => $type['label']], app(CollateralTypes::class)->selectable()),
        ];
    }
}

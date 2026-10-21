<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\FinanceBasis;
use App\Enums\PricingMethod;
use App\Enums\ProductStatus;
use App\Enums\RepaymentFrequency;
use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasSlug, SoftDeletes;

    /**
     * Fields the Shariah board approves. Once an Islamic product is approved these (and its charges)
     * can only change through a new approval request; everything else is commercial and editable.
     */
    public const SHARIAH_TERMS = [
        'pricing_method',
        'default_rate',
        'min_rate',
        'max_rate',
        'repayment_frequency',
        'residual_value_percent',
        'early_settlement_allowed',
        'early_settlement_rebate_percent',
    ];

    /**
     * Status and Shariah approval columns are deliberately not fillable: they are only changed
     * by ProductService through the workflow, never straight from a request.
     */
    protected $fillable = [
        'slug',
        'name',
        'description',
        'category_id',
        'counterpart_product_id',
        'pricing_method',
        'default_rate',
        'min_rate',
        'max_rate',
        'min_amount',
        'max_amount',
        'min_tenure',
        'max_tenure',
        'repayment_frequency',
        'allows_individual',
        'allows_joint',
        'allows_group',
        'min_group_members',
        'max_group_members',
        'min_guarantors',
        'max_guarantors',
        'requires_collateral',
        'grace_period_days',
        'max_dsr_percent',
        'residual_value_percent',
        'early_settlement_allowed',
        'early_settlement_rebate_percent',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'pricing_method' => PricingMethod::class,
        'repayment_frequency' => RepaymentFrequency::class,
        'status' => ProductStatus::class,
        'default_rate' => 'decimal:4',
        'min_rate' => 'decimal:4',
        'max_rate' => 'decimal:4',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'min_tenure' => 'integer',
        'max_tenure' => 'integer',
        'allows_individual' => 'boolean',
        'allows_joint' => 'boolean',
        'allows_group' => 'boolean',
        'min_group_members' => 'integer',
        'max_group_members' => 'integer',
        'min_guarantors' => 'integer',
        'max_guarantors' => 'integer',
        'requires_collateral' => 'boolean',
        'grace_period_days' => 'integer',
        'max_dsr_percent' => 'decimal:2',
        'residual_value_percent' => 'decimal:2',
        'early_settlement_allowed' => 'boolean',
        'early_settlement_rebate_percent' => 'decimal:2',
        'shariah_approved_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Active->value);
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%");
        });
    }

    /**
     * The basis comes from the category (set on the tree root), never stored twice.
     */
    public function financeBasis(): FinanceBasis
    {
        return $this->category->finance_basis;
    }

    public function requiresShariahApproval(): bool
    {
        return $this->financeBasis()->requiresShariahApproval();
    }

    /**
     * True once an Islamic product has been approved: from then on its Shariah terms are locked.
     */
    public function hasLockedShariahTerms(): bool
    {
        return $this->requiresShariahApproval() && $this->shariah_approved_at !== null;
    }

    public function pendingApproval(): ?ApprovalRequest
    {
        return $this->approvalRequests()->where('status', ApprovalStatus::Pending->value)->latest('id')->first();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function counterpart(): BelongsTo
    {
        return $this->belongsTo(self::class, 'counterpart_product_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(ProductCharge::class);
    }

    public function collateralRules(): HasMany
    {
        return $this->hasMany(ProductCollateralRule::class);
    }

    public function documentRequirements(): HasMany
    {
        return $this->hasMany(DocumentRequirement::class);
    }

    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'subject');
    }
}

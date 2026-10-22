<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BorrowerType;
use App\Enums\ClosureReason;
use App\Enums\FinanceBasis;
use App\Enums\LoanStatus;
use App\Enums\PartyRole;
use App\Enums\PricingMethod;
use App\Enums\RepaymentFrequency;
use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasReferenceNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Loan extends Model
{
    use Audited;

    /**
     * Columns whose change alone is not written to the audit trail (see Concerns\Audited).
     */
    public const AUDIT_IGNORED = ['outstanding_principal', 'outstanding_profit', 'outstanding_charges', 'outstanding_penalty', 'arrears_amount', 'days_past_due', 'risk_stage'];

    use BelongsToBranch, HasPublicId, HasReferenceNumber;

    public const REFERENCE_PREFIX = 'APP';

    public const REFERENCE_COLUMN = 'application_no';

    /**
     * Only what an officer types on the application. Status, terms, amounts after approval and
     * the servicing cache are set by the loan services through forceFill, never from a request.
     */
    protected $fillable = [
        'parent_id',
        'product_id',
        'branch_id',
        'borrower_type',
        'borrower_group_id',
        'customer_id',
        'assigned_officer_id',
        'purpose',
        'requested_amount',
        'tenure',
        'created_by',
        'updated_by',
        // Custom fields defined as data (lookups custom_field.loan), validated by the request.
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'borrower_type' => BorrowerType::class,
            'finance_basis' => FinanceBasis::class,
            'pricing_method' => PricingMethod::class,
            'repayment_frequency' => RepaymentFrequency::class,
            'status' => LoanStatus::class,
            'closure_reason' => ClosureReason::class,
            'rate' => 'decimal:4',
            'tenure' => 'integer',
            'requested_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'financed_amount' => 'decimal:2',
            'profit_amount' => 'decimal:2',
            'upfront_charges' => 'decimal:2',
            'net_disbursement_amount' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'residual_value' => 'decimal:2',
            'terms_snapshot' => 'array',
            'assessed_monthly_income' => 'decimal:2',
            'assessed_monthly_expenses' => 'decimal:2',
            'existing_obligations' => 'decimal:2',
            'dsr_percent' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'accepted_at' => 'datetime',
            'disbursed_at' => 'datetime',
            'first_due_date' => 'date',
            'maturity_date' => 'date',
            'income_suspended_at' => 'date',
            'schedule_starts_on' => 'date',
            'renewal_count' => 'integer',
            'closed_at' => 'datetime',
            'outstanding_principal' => 'decimal:2',
            'outstanding_profit' => 'decimal:2',
            'outstanding_charges' => 'decimal:2',
            'outstanding_penalty' => 'decimal:2',
            'arrears_amount' => 'decimal:2',
            'days_past_due' => 'integer',
            'risk_stage' => 'integer',
            'meta' => 'array',
        ];
    }

    protected $hidden = [
        'terms_snapshot',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * Applications and group facilities as staff see them in lists (member loans are shown
     * inside their group facility).
     */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Loans that carry money: individual and joint loans and group member loans, not the group
     * facility (which only adds up its members). Reports use this so nothing is counted twice.
     */
    public function scopeAccounts(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNotNull('loans.parent_id')->orWhere('loans.borrower_type', '!=', BorrowerType::Group->value));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', LoanStatus::closedValues());
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->where('application_no', $search)
                ->orWhere('loan_no', $search)
                ->orWhereHas('customer', fn (Builder $c) => $c->search($search))
                ->orWhereHas('borrowerGroup', fn (Builder $g) => $g->search($search));
        });
    }

    public function isGroupFacility(): bool
    {
        return $this->borrower_type === BorrowerType::Group && $this->parent_id === null;
    }

    public function isMemberLoan(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * This loan's id plus, for a group facility, the ids of its member loans. People and
     * collateral of a group application live on these rows.
     *
     * @return list<int>
     */
    public function idsWithMemberLoans(): array
    {
        return $this->isGroupFacility()
            ? $this->memberLoans()->pluck('id')->push($this->id)->all()
            : [$this->id];
    }

    /**
     * The amount a decision is about: the approved amount once approved, else the request.
     */
    public function decisionAmount(): string
    {
        return (string) ($this->approved_amount ?? $this->requested_amount);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function borrowerGroup(): BelongsTo
    {
        return $this->belongsTo(BorrowerGroup::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function memberLoans(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_officer_id');
    }

    public function parties(): HasMany
    {
        return $this->hasMany(LoanParty::class);
    }

    public function borrowingParties(): HasMany
    {
        return $this->hasMany(LoanParty::class)->whereIn('role', PartyRole::borrowingValues());
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(LoanParty::class)->where('role', PartyRole::Guarantor->value);
    }

    public function collaterals(): HasMany
    {
        return $this->hasMany(Collateral::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('schedule_version')->orderBy('installment_no');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoanTransaction::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LoanActivity::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function recoveryCases(): HasMany
    {
        return $this->hasMany(RecoveryCase::class);
    }

    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'subject');
    }
}

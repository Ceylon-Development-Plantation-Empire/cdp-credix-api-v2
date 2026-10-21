<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Enums\PartyRole;
use App\Enums\RequirementRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRequirement extends Model
{
    protected $fillable = [
        'product_id',
        'party_role',
        'employment_status',
        'requirement_group',
        'rule',
        'document_type_id',
        'min_count',
        'is_mandatory',
    ];

    protected $casts = [
        'party_role' => PartyRole::class,
        'employment_status' => EmploymentStatus::class,
        'rule' => RequirementRule::class,
        'min_count' => 'integer',
        'is_mandatory' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}

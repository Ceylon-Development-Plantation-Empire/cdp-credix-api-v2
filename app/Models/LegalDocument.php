<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Language;
use App\Enums\LegalDocumentType;
use App\Models\Concerns\Audited;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasReferenceNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A legal document prepared for a loan. Branch access follows the loan: a user who cannot see the
 * loan cannot see its documents (queries go through whereHas('loan'), which applies the loan's branch scope).
 */
class LegalDocument extends Model
{
    use Audited, HasPublicId, HasReferenceNumber;

    public const REFERENCE_PREFIX = 'LEG';

    public const REFERENCE_COLUMN = 'reference_no';

    public const AUDIT_IGNORED = ['printed_count', 'last_printed_at'];

    /**
     * Status, print counters and the preparation time are set by the legal service only.
     *
     * @var list<string>
     */
    protected $fillable = [
        'loan_id',
        'template_id',
        'document_type',
        'language',
        'details',
        'remarks',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => LegalDocumentType::class,
            'language' => Language::class,
            'details' => 'array',
            'printed_count' => 'integer',
            'last_printed_at' => 'datetime',
            'prepared_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => 'pending',
    ];

    public function isCreated(): bool
    {
        return $this->status === 'created';
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return BelongsTo<Template, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}

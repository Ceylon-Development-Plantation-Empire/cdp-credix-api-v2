<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BusinessDate;
use App\Enums\DocumentStatus;
use App\Models\Concerns\Audited;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use Audited;
    use HasPublicId, SoftDeletes;

    /**
     * Status and verification columns are set by DocumentService only.
     */
    protected $fillable = [
        'documentable_type',
        'documentable_id',
        'document_type_id',
        'original_name',
        'path',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'issued_on',
        'expires_on',
        'remarks',
        'uploaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'issued_on' => 'date',
            'expires_on' => 'date',
            'verified_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    protected $hidden = [
        'path',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Verified->value);
    }

    /**
     * A document is still acceptable when it has not expired and, if its type has a validity
     * period (salary slips, bank statements …), it was issued within that period.
     */
    public function isCurrent(?int $validityDays): bool
    {
        if ($this->expires_on && $this->expires_on->toDateString() < BusinessDate::today()->toDateString()) {
            return false;
        }

        if ($validityDays !== null) {
            $issued = $this->issued_on ?? $this->created_at;

            return $issued->copy()->addDays($validityDays)->toDateString() > BusinessDate::today()->toDateString();
        }

        return true;
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}

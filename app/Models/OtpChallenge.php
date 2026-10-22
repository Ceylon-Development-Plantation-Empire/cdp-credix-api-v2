<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OtpChallenge extends Model
{
    use HasPublicId;

    protected $fillable = [
        'purpose',
        'subject_type',
        'subject_id',
        'destination',
        'code_hash',
        'payload',
        'payload_hash',
        'max_attempts',
        'expires_at',
        'ip_address',
        'user_agent',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'status' => OtpStatus::class,
            'payload' => 'array',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'send_count' => 'integer',
        ];
    }

    protected $hidden = [
        'code_hash',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    public function isPending(): bool
    {
        return $this->status === OtpStatus::Pending;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}

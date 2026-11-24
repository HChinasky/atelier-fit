<?php

namespace App\Models;

use Database\Factories\OtpCodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-time sign-in code issued to an e-mail address. Holds only the HASH of
 * the code — the plaintext exists solely in the message that carried it (and
 * in the log mailer's local-only output). See OtpService for the send/verify
 * lifecycle and the single-use guarantee.
 */
class OtpCode extends Model
{
    /** @use HasFactory<OtpCodeFactory> */
    use HasFactory;

    protected $fillable = [
        'email',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** Codes still eligible for verification: not consumed yet. */
    public function scopeUnconsumed(Builder $query): Builder
    {
        return $query->whereNull('consumed_at');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}

<?php

namespace App\Models;

use Database\Factories\MarketingConsentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permission to send marketing on a captured contact — separate from the contact
 * itself, because handing over a contact to use the service and agreeing to be
 * marketed to are different things.
 *
 * Withdrawal stamps unsubscribed_at rather than deleting the row: opting out has
 * to be provable too, and a missing row would read as "never subscribed".
 */
class MarketingConsent extends Model
{
    /** @use HasFactory<MarketingConsentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'lead_id',
        'channel',
        'consent_version',
        'consented_at',
        'unsubscribed_at',
        'ip',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /** The contact this permission was given for. */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** Still subscribed — the only state in which marketing may be sent. */
    public function isActive(): bool
    {
        return $this->unsubscribed_at === null;
    }

    /**
     * Withdraw the permission. Free and immediate by design, and idempotent: a
     * second unsubscribe must not move the date and rewrite when they opted out.
     */
    public function unsubscribe(): void
    {
        if ($this->unsubscribed_at !== null) {
            return;
        }

        $this->forceFill(['unsubscribed_at' => now()])->save();
    }

    /**
     * @param  Builder<MarketingConsent>  $query
     * @return Builder<MarketingConsent>
     */
    public function scopeSubscribed(Builder $query): Builder
    {
        return $query->whereNull('unsubscribed_at');
    }
}

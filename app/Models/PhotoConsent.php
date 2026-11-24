<?php

namespace App\Models;

use Database\Factories\PhotoConsentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A recorded photo-processing consent: which version of the wording was accepted,
 * and when. Written before the photo is stored; kept for the consent retention
 * period so the consent can still be demonstrated after the photo itself is gone.
 */
class PhotoConsent extends Model
{
    /** @use HasFactory<PhotoConsentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'consent_version',
        'terms_version',
        'accepted_at',
        'terms_accepted_at',
        'ip',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
        ];
    }

    /**
     * Whether this consent was given for the wording currently in force. A record
     * made against an older version no longer authorises new processing — the
     * visitor has to agree to the current text.
     */
    public function isCurrent(): bool
    {
        return $this->consent_version === (string) config('consent.version');
    }
}

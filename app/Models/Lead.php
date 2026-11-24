<?php

namespace App\Models;

use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    /**
     * Mass-assignable attributes. Written by the lead-capture endpoint; user_id
     * only by the member-lead mint in FittingRoomController (a lead with an
     * owner debits that owner's credits — see LeadToken for why that is safe).
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'channel',
        'contact',
        'tags',
        'ip',
        'notified_at',
        // First-touch campaign attribution, copied from the session at capture
        // (see TrackAttribution). Internal-only; never rendered or forwarded.
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'landing_path',
        'referrer_host',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'notified_at' => 'datetime',
        ];
    }

    /** The member who owns this lead; null for guest (anonymous) leads. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

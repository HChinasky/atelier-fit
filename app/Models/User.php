<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_stylist' => 'boolean',
        ];
    }

    /**
     * Administrators reach the internal colour-analysis tool; nobody else does.
     *
     * Deliberately NOT in $fillable: a mass-assignable admin flag is a
     * privilege-escalation bug waiting for one careless ->fill($request->all()).
     * It is set by hand in the database, which is the same bar as deploying.
     */
    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * May this account label colour profiles?
     *
     * An administrator counts as a stylist. Not because the two jobs are the
     * same, but because the alternative is an administrator locking themselves
     * out of a screen they can grant themselves access to in one command — a
     * gate that only costs a round trip is not a gate.
     */
    public function isStylist(): bool
    {
        return (bool) $this->is_stylist || $this->isAdmin();
    }

    /** The member's try-on credit ledger (balance = sum of deltas). */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /** Leads captured by this member (guest leads have no owner). */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** The member's answers to the style quiz, if they have given any. */
    public function styleProfile(): HasOne
    {
        return $this->hasOne(StyleProfile::class);
    }

    /**
     * The analysis whose season is the member's (color_profile_id). Moved only
     * by AnalyzeColorProfile when the member's own photo places a season —
     * deliberately not fillable.
     */
    public function colorProfile(): BelongsTo
    {
        return $this->belongsTo(ColorProfile::class);
    }

    /** Google / Apple identities that sign in to this account. */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** Hearted pieces ("Обране"), shared by every heart on the account. */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }
}

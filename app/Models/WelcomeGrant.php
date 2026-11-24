<?php

namespace App\Models;

use Database\Factories\WelcomeGrantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The marker that an E-MAIL ADDRESS has received its welcome credits. Not
 * linked to users on purpose: it must survive account deletion so signing up
 * again with the same address never re-triggers the grant. Stores only an
 * HMAC of the address (see CreditService::identityHash) — never the address.
 *
 * It outlives the account, but no longer forever: erasing the account stamps
 * account_removed_at, and the retention sweep drops the row a configured period
 * later. A hash whose account still exists carries no date and is never swept.
 */
class WelcomeGrant extends Model
{
    /** @use HasFactory<WelcomeGrantFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'identity_hash',
        'account_removed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_removed_at' => 'datetime',
        ];
    }
}

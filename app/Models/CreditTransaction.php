<?php

namespace App\Models;

use Database\Factories\CreditTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ledger entry of a member's try-on credits. Append-only: the balance is
 * always SUM(delta) for the user — rows are never edited or deleted (except by
 * the user_id FK cascade when the account itself goes).
 *
 * Reasons: `welcome` (+N signup grant), `spend` (-N, tied to a generation),
 * `refund` (+N, compensates a failed generation's spend).
 */
class CreditTransaction extends Model
{
    /** @use HasFactory<CreditTransactionFactory> */
    use HasFactory;

    public const REASON_WELCOME = 'welcome';

    public const REASON_SPEND = 'spend';

    public const REASON_REFUND = 'refund';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'generation_id',
        'delta',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delta' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}

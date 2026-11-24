<?php

namespace App\Models;

use App\Services\Onboarding\StyleQuiz;
use Database\Factories\StyleProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member's answers to the style quiz. What a question is and what counts as
 * an answer lives in StyleQuiz; this row only keeps them.
 *
 * @property int $id
 * @property int $user_id
 * @property array<string, mixed>|null $answers
 * @property Carbon|null $completed_at
 * @property Carbon|null $skipped_at
 */
class StyleProfile extends Model
{
    /** @use HasFactory<StyleProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'answers',
        'completed_at',
        'skipped_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'completed_at' => 'datetime',
            'skipped_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether the quiz has been through the after-sign-up offer, either way. */
    public function wasOffered(): bool
    {
        return $this->completed_at !== null || $this->skipped_at !== null;
    }

    /**
     * The answers the colour engine is given.
     *
     * @return array<string, bool|string>|null
     */
    public function colourQuiz(): ?array
    {
        return StyleQuiz::colourQuiz($this->answers);
    }
}

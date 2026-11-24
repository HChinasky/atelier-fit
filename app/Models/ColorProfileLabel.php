<?php

namespace App\Models;

use Database\Factories\ColorProfileLabelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stylist's verdict on one colour profile.
 *
 * Kept apart from the engine's own answer on purpose — see the migration. The
 * comparison of the two is the entire point of collecting these, so nothing
 * here may write to color_profiles.
 *
 * @property string $season
 * @property string|null $season_alt
 * @property string|null $undertone
 * @property string|null $depth
 * @property string|null $chroma
 * @property string $stylist_confidence
 * @property string|null $notes
 */
class ColorProfileLabel extends Model
{
    /** @use HasFactory<ColorProfileLabelFactory> */
    use HasFactory;

    /**
     * The engine's own 12 slugs. Listed here rather than derived, so a season
     * the engine cannot return can never be labelled — the two vocabularies
     * have to stay the same one or the report compares nothing.
     *
     * @var list<string>
     */
    public const SEASONS = [
        'light_spring', 'true_spring', 'bright_spring',
        'light_summer', 'true_summer', 'soft_summer',
        'soft_autumn', 'true_autumn', 'deep_autumn',
        'deep_winter', 'true_winter', 'bright_winter',
    ];

    /** @var list<string> */
    public const UNDERTONES = ['warm', 'cool', 'neutral'];

    /** @var list<string> */
    public const DEPTHS = ['light', 'medium', 'deep'];

    /** @var list<string> */
    public const CHROMAS = ['bright', 'soft'];

    /** @var list<string> */
    public const CONFIDENCES = ['high', 'medium', 'low'];

    /**
     * The four families. A season slug ends in its family, so this is a suffix
     * match rather than a table — and the report's "did they at least agree on
     * the family" question is exactly that suffix.
     *
     * @var list<string>
     */
    public const FAMILIES = ['spring', 'summer', 'autumn', 'winter'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'color_profile_id',
        'user_id',
        'season',
        'season_alt',
        'undertone',
        'depth',
        'chroma',
        'stylist_confidence',
        'notes',
    ];

    /**
     * @return BelongsTo<ColorProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ColorProfile::class, 'color_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The family a season slug belongs to: spring, summer, autumn or winter.
     */
    public static function family(?string $season): ?string
    {
        if (! is_string($season)) {
            return null;
        }

        foreach (self::FAMILIES as $family) {
            if (str_ends_with($season, $family)) {
                return $family;
            }
        }

        return null;
    }

    /**
     * Everything a stylist said, and nothing about who they are.
     *
     * What the export takes. The user_id is deliberately absent: the dataset is
     * measurements and verdicts, not a record of who said what about whom.
     *
     * @return array<string, mixed>
     */
    public function asDataset(): array
    {
        return [
            'season' => $this->season,
            'season_alt' => $this->season_alt,
            'undertone' => $this->undertone,
            'depth' => $this->depth,
            'chroma' => $this->chroma,
            'stylist_confidence' => $this->stylist_confidence,
            'notes' => $this->notes,
            'labelled_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Models;

use Database\Factories\ColorProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of the colour engine over one photo.
 *
 * Holds only what was MEASURED. The photo and the rendered debug views live in
 * color_profile_images on a 60-hour clock; once the purge has run this row is
 * still a complete record of the analysis, which is the whole reason the two are
 * separate tables.
 */
class ColorProfile extends Model
{
    /** @use HasFactory<ColorProfileFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_OK = 'ok';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'person_ref',
        'lighting',
        'status',
        'reject_reason',
        'samples',
        'zones',
        'white_balance',
        'axes',
        'contrast',
        'season',
        'seasons',
        'classifier',
        'confidence',
        'quiz',
        'algo_version',
        'target_calibrated',
        'source',
        'consented_at',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'samples' => 'array',
            'zones' => 'array',
            'white_balance' => 'array',
            'axes' => 'array',
            'contrast' => 'array',
            'seasons' => 'array',
            'classifier' => 'array',
            'quiz' => 'array',
            'confidence' => 'float',
            'target_calibrated' => 'boolean',
            'consented_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ColorProfileImage::class);
    }

    /**
     * Stylists' verdicts on this profile. Never merged into `season`, which is
     * the engine's: the comparison of the two is the point of collecting them.
     *
     * @return HasMany<ColorProfileLabel, $this>
     */
    public function labels(): HasMany
    {
        return $this->hasMany(ColorProfileLabel::class);
    }

    /**
     * One person's verdict on this profile, if they have given one.
     *
     * Reads an already-loaded relation when there is one, so a queue of fifty
     * rows is one query rather than fifty-one.
     */
    public function labelBy(User $user): ?ColorProfileLabel
    {
        if ($this->relationLoaded('labels')) {
            return $this->labels->firstWhere('user_id', $user->id);
        }

        return $this->labels()->where('user_id', $user->id)->first();
    }

    /**
     * The lighting conditions the engine's fixture set is shot under. The same
     * vocabulary, so a calibration set gathered here and one gathered on disk
     * are the same set.
     *
     * @var list<string>
     */
    public const LIGHTINGS = ['daylight', 'indoor_warm', 'indoor_cool', 'flash', 'mixed'];

    /**
     * Is this season still an unchecked draft?
     *
     * Reads the engine's own flag rather than a config value: the classifier
     * carries it in the contract because it is a property of the DATA that
     * decided this profile, and a profile analysed before the data was approved
     * is still a draft afterwards.
     */
    public function isDraftClassification(): bool
    {
        return (bool) ($this->classifier['draft'] ?? true);
    }

    public function hasLowConfidence(): bool
    {
        return (bool) ($this->classifier['low_confidence'] ?? false);
    }

    /**
     * The seasons the engine ranked, best first, as [slug => score].
     *
     * @return array<string, float>
     */
    public function rankedSeasons(): array
    {
        $ranked = [];

        foreach ($this->seasons ?? [] as $entry) {
            if (is_array($entry) && isset($entry['slug'])) {
                $ranked[(string) $entry['slug']] = (float) ($entry['score'] ?? 0.0);
            }
        }

        return $ranked;
    }

    /**
     * Is the engine still working on it? The page polls while this is true.
     */
    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }

    /**
     * A finished analysis, whatever the verdict. 'rejected' is a finished
     * analysis: the engine looked and said no.
     */
    public function isSettled(): bool
    {
        return ! $this->isPending();
    }
}

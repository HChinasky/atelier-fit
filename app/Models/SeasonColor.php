<?php

namespace App\Models;

use Database\Factories\SeasonColorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One colour in one season's palette.
 *
 * @property string $hex
 * @property float $lab_l
 * @property float $lab_a
 * @property float $lab_b
 * @property string $group
 * @property bool $is_recommended
 * @property int $sort
 * @property bool $is_draft
 * @property string $source
 */
class SeasonColor extends Model
{
    /** @use HasFactory<SeasonColorFactory> */
    use HasFactory;

    public const GROUP_NEUTRALS = 'neutrals';

    public const GROUP_BASE = 'base';

    public const GROUP_ACCENTS = 'accents';

    public const GROUP_AVOID = 'avoid';

    /**
     * The order the page shows them in: what to build a wardrobe on, then what
     * to wear, then what to wear loudly, and only then what to keep away from.
     *
     * @var list<string>
     */
    public const GROUPS = [
        self::GROUP_NEUTRALS,
        self::GROUP_BASE,
        self::GROUP_ACCENTS,
        self::GROUP_AVOID,
    ];

    /**
     * Written by the seeder, and the only rows it will ever delete or rewrite.
     */
    public const SOURCE_GENERATOR = 'generator';

    /**
     * Chosen by a person. No seeder touches these, ever.
     */
    public const SOURCE_STYLIST = 'stylist';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'season_id',
        'hex',
        'lab_l',
        'lab_a',
        'lab_b',
        'group',
        'is_recommended',
        'sort',
        'is_draft',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lab_l' => 'float',
            'lab_a' => 'float',
            'lab_b' => 'float',
            'is_recommended' => 'boolean',
            'is_draft' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * The translated group heading, falling back to the slug.
     */
    public function groupName(): string
    {
        $key = 'seasons.groups.'.$this->group;
        $name = __($key);

        return is_string($name) && $name !== $key ? $name : ucfirst($this->group);
    }

    /**
     * Black or white text on this swatch, whichever a person can actually read.
     *
     * Decided on L*, not on the RGB average: L* is perceptual lightness, which
     * is the whole question being asked. The threshold is the usual one for
     * CIELAB — a mid-grey at L* 60 is already light enough to want dark text.
     */
    public function readableInk(): string
    {
        return $this->lab_l >= 60.0 ? '#2D2D2D' : '#FFFFFF';
    }
}

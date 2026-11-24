<?php

namespace App\Models;

use Database\Factories\SeasonFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the 12 seasons.
 *
 * Carries a slug and an order and nothing readable. The name and the description
 * are copy, they are translated, and they live in lang/{locale}/seasons.php —
 * putting them in a column would mean a migration every time somebody rewords a
 * sentence, and one language winning by accident.
 *
 * @property string $slug
 * @property int $sort
 * @property-read Collection<int, SeasonColor> $colors
 */
class Season extends Model
{
    /** @use HasFactory<SeasonFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['slug', 'sort'];

    /**
     * @return HasMany<SeasonColor, $this>
     */
    public function colors(): HasMany
    {
        return $this->hasMany(SeasonColor::class)->orderBy('sort');
    }

    /**
     * The translated name, falling back to the slug rather than to silence.
     *
     * A missing translation should look like a missing translation — "deep
     * winter" is still readable, and an empty heading is a bug nobody can see.
     */
    public function name(): string
    {
        $key = 'seasons.names.'.$this->slug;
        $name = __($key);

        return is_string($name) && $name !== $key
            ? $name
            : ucwords(str_replace('_', ' ', $this->slug));
    }

    public function description(): ?string
    {
        $key = 'seasons.descriptions.'.$this->slug;
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : null;
    }

    /**
     * The palette grouped the way it is shown: neutrals, base, accents, avoid.
     *
     * @return array<string, Collection<int, SeasonColor>>
     */
    public function palette(): array
    {
        return $this->colors
            ->sortBy([['group', 'asc'], ['sort', 'asc']])
            ->groupBy('group')
            ->map(fn ($colors) => $colors->values())
            ->all();
    }

    /**
     * Is any of this palette still a draft nobody has checked?
     */
    public function isDraft(): bool
    {
        return $this->colors->contains(fn (SeasonColor $color) => $color->is_draft);
    }
}

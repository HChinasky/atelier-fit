<?php

namespace App\Models;

use Database\Factories\ProductSeasonMatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one product's measured colour sits against one season's palette.
 * Written by GarmentPaletteMatcher only; see it for what the numbers mean.
 *
 * @property int $product_id
 * @property string $season
 * @property bool $suits
 * @property float $score
 * @property float $delta_e
 * @property string|null $palette_group
 */
class ProductSeasonMatch extends Model
{
    /** @use HasFactory<ProductSeasonMatchFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'season',
        'suits',
        'score',
        'delta_e',
        'palette_group',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suits' => 'boolean',
            'score' => 'float',
            'delta_e' => 'float',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

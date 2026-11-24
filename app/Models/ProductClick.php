<?php

namespace App\Models;

use Database\Factories\ProductClickFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member followed a product's buy link out to the shop.
 *
 * @property int $user_id
 * @property int $product_id
 * @property string $source
 * @property string|null $season
 */
class ProductClick extends Model
{
    /** @use HasFactory<ProductClickFactory> */
    use HasFactory;

    /** Where the click came from. */
    public const SOURCES = ['color', 'catalog', 'wardrobe', 'tryon', 'capsule', 'looks'];

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'product_id',
        'source',
        'season',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

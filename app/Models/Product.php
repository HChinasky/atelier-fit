<?php

namespace App\Models;

use App\Services\Catalog\CategoryChips;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Every category the catalog holds (the products CHECK constraint). Shoes
     * and headwear complete a capsule; the try-on engine dresses only the
     * first three.
     */
    public const CATEGORIES = ['upper_body', 'lower_body', 'dresses', 'shoes', 'headwear'];

    /** The body regions the try-on engine can dress. */
    public const TRY_ON_CATEGORIES = ['upper_body', 'lower_body', 'dresses'];

    public const STYLES = ['casual', 'formal', 'evening', 'business', 'sport'];

    public const OCCASIONS = ['office', 'evening', 'casual', 'universal'];

    /** A base piece: no occasion of its own, so it goes with any. */
    public const OCCASION_UNIVERSAL = 'universal';

    /**
     * Who set style/occasion, weakest first: a source only overwrites a tag
     * set by itself or by a weaker one, so re-parsing never undoes a stylist.
     */
    public const STYLE_SOURCES = ['parse', 'feed', 'ai', 'manual'];

    /**
     * Mass-assignable attributes. Fed by the feed importer's upsert.
     *
     * @var list<string>
     */
    protected $fillable = [
        'external_id',
        'brand_name',
        'product_name',
        'price_aed',
        'image_flat_url',
        'shop_url',
        'category',
        'feed_category',
        'flat_bg_verified',
        'raw',
    ];

    /**
     * The category chip this product sits under on the account pages: the
     * feed's category worded for the interface (see CategoryChips), or null
     * when the feed did not say.
     *
     * @return Attribute<string|null, never>
     */
    protected function categoryLabel(): Attribute
    {
        return Attribute::get(fn () => CategoryChips::label($this->feed_category));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_aed' => 'decimal:2',
            'flat_bg_verified' => 'boolean',
            'raw' => 'array',
            'color' => 'array',
            'color_measured_at' => 'datetime',
            'style_evidence' => 'array',
            'style_tagged_at' => 'datetime',
        ];
    }

    /**
     * The measured colour's status: ok, rejected (the engine could not
     * measure the photo) or failed (the photo or the service was not there).
     * Deliberately not fillable, like color itself: only the measuring job
     * writes them, never the feed.
     */
    public const COLOR_OK = 'ok';

    public const COLOR_REJECTED = 'rejected';

    public const COLOR_FAILED = 'failed';

    /**
     * Only what the try-on engine can dress: the catalog, the dashboard and
     * the generation endpoints never offer shoes or headwear.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeTryOnable(Builder $query): void
    {
        $query->whereIn($query->qualifyColumn('category'), self::TRY_ON_CATEGORIES);
    }

    /**
     * Only the sources the catalog is currently showing. While
     * CATALOG_AMAZON_ONLY is on (the default), that is the Amazon.ae catalog:
     * an Amazon item keeps its ASIN in the feed snapshot, and nothing else
     * does. The other sources stay in the database and in the seed files,
     * switched off rather than deleted, so turning the flag off brings them
     * straight back.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeFromActiveSources(Builder $query): void
    {
        if (config('catalog.amazon_only')) {
            $query->whereNotNull($query->qualifyColumn('raw->ASIN'));
        }
    }

    /** How this product sits against each season's palette. */
    public function seasonMatches(): HasMany
    {
        return $this->hasMany(ProductSeasonMatch::class);
    }

    /**
     * The feed's own description of the piece, or null when it gave none.
     * Google Merchant items carry <description>; the Amazon PA-API shape
     * carries it as EditorialReviews/EditorialReview/Content (one or many).
     */
    public function description(): ?string
    {
        $raw = $this->raw ?? [];

        $candidates = [$raw['description'] ?? null];
        $reviews = $raw['EditorialReviews']['EditorialReview'] ?? [];
        foreach (array_is_list($reviews) ? $reviews : [$reviews] as $review) {
            $candidates[] = is_array($review) ? ($review['Content'] ?? null) : null;
        }

        foreach ($candidates as $text) {
            if (is_string($text) && trim($text) !== '') {
                return trim($text);
            }
        }

        return null;
    }

    /**
     * Every photo of the piece for the detail gallery: the try-on photo first,
     * then any extra shots the feed lists (Google Merchant
     * <additional_image_link>, Amazon ImageSets/ImageSet/LargeImage/URL).
     * Only absolute http(s) URLs, each once.
     *
     * @return list<string>
     */
    public function galleryUrls(): array
    {
        $raw = $this->raw ?? [];

        $extra = $raw['additional_image_link'] ?? [];
        $extra = is_array($extra) ? $extra : [$extra];

        $sets = $raw['ImageSets']['ImageSet'] ?? [];
        foreach (array_is_list($sets) ? $sets : [$sets] as $set) {
            $extra[] = is_array($set) ? ($set['LargeImage']['URL'] ?? null) : null;
        }

        $urls = [$this->image_flat_url];
        foreach ($extra as $url) {
            if (is_string($url) && preg_match('#^https?://#i', trim($url))) {
                $urls[] = trim($url);
            }
        }

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * The garment's colours as measured, largest share first, or [] when it
     * has not been measured (or could not be).
     *
     * @return list<array{L: float, a: float, b: float, hex: string, share: float}>
     */
    public function measuredColours(): array
    {
        return $this->color_status === self::COLOR_OK ? ($this->color['colours'] ?? []) : [];
    }

    /**
     * The main measured colour as #RRGGBB, for a swatch, or null. Only a
     * well-formed hex: it goes into a style attribute.
     */
    public function mainHex(): ?string
    {
        $hex = $this->measuredColours()[0]['hex'] ?? null;

        return is_string($hex) && preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) ? strtoupper($hex) : null;
    }
}

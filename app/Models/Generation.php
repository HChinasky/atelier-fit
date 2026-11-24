<?php

namespace App\Models;

use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'lead_id',
        'product_id',
        'product_ids',
        'client_photo',
        'source_photo',
        'status',
        'fashn_prediction_id',
        'outfit_step',
        'result_urls',
        'result_keys',
        'share_keys',
        'error',
        'num_samples',
        'with_background',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_ids' => 'array',
            'result_urls' => 'array',
            'result_keys' => 'array',
            'share_keys' => 'array',
            'num_samples' => 'integer',
            'outfit_step' => 'integer',
            'with_background' => 'boolean',
            'expires_at' => 'datetime',
            'wardrobe_hidden_at' => 'datetime',
            'wardrobe_hidden_product_ids' => 'array',
        ];
    }

    /**
     * An outfit is a generation that chains several products in sequence; a single
     * try-on leaves product_ids null. Two-plus entries make it an outfit.
     */
    public function isOutfit(): bool
    {
        return is_array($this->product_ids) && count($this->product_ids) > 1;
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}

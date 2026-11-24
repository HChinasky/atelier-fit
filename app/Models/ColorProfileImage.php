<?php

namespace App\Models;

use Database\Factories\ColorProfileImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The photo, and the debug views rendered from it.
 *
 * A private storage KEY, never a URL: these are served only through an
 * authorised controller. The object itself is swept by app:purge-expired-photos
 * off the shared photo prefix; this row is dropped by the same command once
 * expires_at has passed, so nothing is left pointing at a deleted object.
 */
class ColorProfileImage extends Model
{
    /** @use HasFactory<ColorProfileImageFactory> */
    use HasFactory;

    /** The uploaded photo itself, as opposed to a rendered view of it. */
    public const KIND_SOURCE = 'source';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'color_profile_id',
        'kind',
        'disk',
        'path',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ColorProfile::class, 'color_profile_id');
    }
}

<?php

namespace App\Services\Outfit;

/**
 * Machine-readable reason a chosen SET of garments is not a valid outfit (checked
 * before any fal call, so a bad set never spends). Mirrors the photo gate's
 * ValidationFailureReason: each case exposes its i18n key so the rejection carries
 * a translatable, user-facing message rather than a hardcoded string.
 */
enum OutfitRejection: string
{
    case TooFewItems = 'too_few_items';
    case TooManyItems = 'too_many_items';
    case DressMustBeSolo = 'dress_must_be_solo';
    case DuplicateLayer = 'duplicate_layer';
    case UnsupportedCategory = 'unsupported_category';

    /**
     * Translation key under the "outfit" group (lang/<locale>/outfit.php).
     */
    public function messageKey(): string
    {
        return 'outfit.'.$this->value;
    }
}

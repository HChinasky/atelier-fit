<?php

namespace App\Services\Feed;

/**
 * Default binding for this step: treat every product as NOT yet verified
 * (flat_bg_verified = false). Conservative — products stay out of the catalog
 * until the real verifier (next step) confirms the photo is try-on ready.
 */
class DefaultFlatBackgroundVerifier implements FlatBackgroundVerifier
{
    public function verify(ProductData $product): bool
    {
        return false;
    }
}

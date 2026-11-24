<?php

namespace App\Services\Feed;

/**
 * Extension point for deciding whether a product's flat photo is usable for
 * virtual try-on (sets products.flat_bg_verified). The real implementation —
 * which inspects the image — is a separate step; the job depends only on this
 * contract so that logic can be swapped via the container binding.
 */
interface FlatBackgroundVerifier
{
    public function verify(ProductData $product): bool;
}

<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a catalog product. Deliberately a strict allow-list: only the
 * fields the storefront needs are exposed. Internal/operational columns — the
 * raw jsonb feed object, flat_bg_verified, timestamps — are never serialized,
 * so they cannot leak through the API by accident.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'brand_name' => $this->brand_name,
            'product_name' => $this->product_name,
            'price_aed' => $this->price_aed,
            'image_flat_url' => $this->image_flat_url,
            'category' => $this->category,
            'shop_url' => $this->shop_url,
        ];
    }
}

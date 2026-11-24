<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnalyticsEventRequest;
use App\Models\Product;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\Response;

/**
 * First-party sink for browser-only analytics events (Этап A3). This is OUR
 * endpoint on OUR domain — no analytics script, cookie or pixel is involved
 * (the published Cookie Policy promise); the browser just tells our server
 * "the visitor tapped X" and the server captures it exactly like any
 * server-side event, on the same pseudonymous session identity.
 *
 * Product references arrive as an id only and are enriched from OUR catalog
 * row (brand, category, price) — the client is never trusted to describe a
 * product, so the analytics stream cannot be fed made-up values.
 */
class AnalyticsEventController extends Controller
{
    /**
     * POST /api/analytics/event
     */
    public function store(StoreAnalyticsEventRequest $request, AnalyticsService $analytics): Response
    {
        $event = (string) $request->validated('event');
        $props = $request->eventProps();

        if (isset($props['product_id'])) {
            $props = array_merge($props, $this->productProps((int) $props['product_id']));
        }

        $analytics->capture($event, $props);

        return response()->noContent();
    }

    /**
     * Catalog facts about the referenced product, from our own row.
     *
     * @return array<string, mixed>
     */
    private function productProps(int $productId): array
    {
        $product = Product::query()->find($productId);

        if ($product === null) {
            return [];
        }

        return array_filter([
            'brand' => $product->brand_name,
            'category' => $product->category,
            'price' => $product->price_aed !== null ? (float) $product->price_aed : null,
            'currency' => $product->price_aed !== null ? 'AED' : null,
        ], fn ($v) => $v !== null);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogIndexRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogController extends Controller
{
    /**
     * GET /api/catalog — the storefront catalog.
     *
     * Production contract: only try-on-ready products (flat_bg_verified = true)
     * are returned, and only what it can dress (no shoes or headwear, which
     * live in the catalog for capsules). An optional ?category filter narrows
     * by FASHN category, and results are paginated in a stable order.
     */
    public function index(CatalogIndexRequest $request, AnalyticsService $analytics): AnonymousResourceCollection
    {
        $products = Product::query()
            ->tryOnable()
            ->fromActiveSources()
            ->when($this->verifiedOnly(), fn ($query) => $query->where('flat_bg_verified', true))
            ->when(
                $request->validated('category'),
                fn ($query, string $category) => $query->where('category', $category),
            )
            // Deterministic order so pagination is stable across pages.
            ->orderBy('id')
            ->paginate($request->validated('per_page') ?? config('catalog.per_page'))
            ->withQueryString();

        // First page only — the funnel signal is "the catalog was seen", not
        // "a page was flipped".
        if ($products->currentPage() === 1) {
            $analytics->capture('catalog_viewed', ['items_count' => $products->total()]);
        }

        return ProductResource::collection($products);
    }

    /**
     * Whether the catalog is restricted to verified products.
     *
     * Always true in production. Only a non-production environment with the
     * explicit dev flag enabled may relax it — so unverified products can never
     * reach real users, regardless of configuration.
     */
    private function verifiedOnly(): bool
    {
        $showUnverified = config('catalog.show_unverified') && ! app()->isProduction();

        return ! $showUnverified;
    }
}

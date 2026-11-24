<?php

namespace App\Http\Controllers\Membership;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductClick;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /account/shop/{product} — count the click, then send the member to the
 * shop.
 *
 * The destination is the product's own shop_url from the database (hard
 * constraint 5: never a shop name or link in a template), and only an http(s)
 * one: a feed row with anything else in it is a 404, not a redirect to it.
 */
class ShopRedirectController extends Controller
{
    public function __invoke(Request $request, Product $product): RedirectResponse
    {
        $url = (string) $product->shop_url;
        abort_unless(in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true), 404);

        $source = $request->query('from');
        $user = $request->user();

        ProductClick::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'source' => in_array($source, ProductClick::SOURCES, true) ? $source : 'catalog',
            'season' => $user->colorProfile?->season,
        ]);

        return redirect()->away($url);
    }
}

<?php

namespace App\Http\Controllers\Membership;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hearting a piece and un-hearting it. Both are idempotent, so a double tap
 * or a retried request settles on the same state.
 */
class FavoriteController extends Controller
{
    public function store(Request $request, Product $product): JsonResponse
    {
        Favorite::query()->firstOrCreate([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
        ]);

        return response()->json(['liked' => true]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $request->user()->favorites()->where('product_id', $product->id)->delete();

        return response()->json(['liked' => false]);
    }
}

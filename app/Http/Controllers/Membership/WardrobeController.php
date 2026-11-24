<?php

namespace App\Http\Controllers\Membership;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Taking a set, or a piece of it, out of the member's wardrobe.
 *
 * Hiding, not deleting: the generation row stays for the rate limit and the
 * credit ledger (see the migration). Both are idempotent, and anybody else's
 * look — or a piece the look was not made of — is a plain 404.
 */
class WardrobeController extends Controller
{
    public function hideLook(Request $request, Generation $generation): JsonResponse
    {
        $this->authorizeOwner($request, $generation);

        if ($generation->wardrobe_hidden_at === null) {
            $generation->forceFill(['wardrobe_hidden_at' => now()])->save();
        }

        return response()->json(['hidden' => true]);
    }

    public function hidePiece(Request $request, Generation $generation, int $product): JsonResponse
    {
        $this->authorizeOwner($request, $generation);

        $pieces = array_map('intval', $generation->product_ids ?: [$generation->product_id]);
        abort_unless(in_array($product, $pieces, true), 404);

        $hidden = array_map('intval', $generation->wardrobe_hidden_product_ids ?? []);
        if (! in_array($product, $hidden, true)) {
            $generation->forceFill(['wardrobe_hidden_product_ids' => [...$hidden, $product]])->save();
        }

        return response()->json(['hidden' => true]);
    }

    private function authorizeOwner(Request $request, Generation $generation): void
    {
        abort_unless($generation->lead?->user_id === $request->user()->id, 404);
    }
}

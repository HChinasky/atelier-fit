<?php

namespace App\Http\Controllers\Membership;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePhotoRequest;
use App\Models\Product;
use App\Services\Catalog\TryOnChips;
use App\Services\Color\ColorRecommendations;
use App\Services\Generation\MemberLead;
use App\Services\Photo\ClientPhotoStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The two-step "Примірка" (the design hand-over, step2-select.html)
 * for the client to review, next to the one-screen version on the phone's /account:
 * step 1 takes the photo, step 2 picks the pieces and runs the try-on on the
 * dashboard's endpoints with the member's own lead. The uploaded photo lives
 * in the session between them, so step 2 without one goes back to step 1.
 * Not linked from the account yet.
 */
class TryOnController extends Controller
{
    /** The member's uploaded photo: {key, consent_id}. */
    public const SESSION_KEY = 'tryon.photo';

    /** GET /account/try-on/step-1 — "Крок 1 · Додай фото". */
    public function upload(Request $request): View
    {
        return view('membership.tryon.upload', [
            'locale' => app()->getLocale(),
            'consentVersion' => (string) config('consent.version'),
            'termsVersion' => (string) config('terms.version'),
            'photoRoute' => route('membership.account.try-on.photo.store'),
        ]);
    }

    /**
     * POST /account/try-on/photo — stores the photo, remembers it for step 2
     * and tells the page where to go next.
     */
    public function storePhoto(StorePhotoRequest $request, ClientPhotoStore $photos): JsonResponse
    {
        $stored = $photos->store($request);

        $request->session()->put(self::SESSION_KEY, [
            'key' => $stored['photo_key'],
            'consent_id' => $stored['consent_id'],
        ]);

        return response()->json(['redirect' => route('membership.account.try-on.step2')], 201);
    }

    /**
     * GET /account/try-on/photo — the member's own photo for step 2's avatar.
     * Only ever the key in this member's session, never one from the request.
     */
    public function photo(Request $request): Response
    {
        $key = $this->photoKey($request);
        abort_unless($key, 404);

        return Storage::disk((string) config('fitting_room.photo.disk'))
            ->response($key, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    /** GET /account/try-on/step-2 — "Крок 2 · Обери одяг". */
    public function select(Request $request, MemberLead $memberLead, ColorRecommendations $recommendations): View|RedirectResponse
    {
        $locale = app()->getLocale();
        if (! $this->photoKey($request)) {
            return redirect()->route('membership.account.try-on.step1');
        }

        $season = config('features.color_analysis') ? $request->user()->colorProfile?->season : null;

        $products = Product::where('flat_bg_verified', true)
            ->tryOnable()
            ->fromActiveSources()
            ->orderByDesc('id')
            ->get();

        return view('membership.tryon.select', [
            'locale' => $locale,
            'products' => $products,
            'brands' => $products->pluck('brand_name')->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values(),
            // What the filters match on: one fixed chip per piece (TryOnChips).
            'catalog' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'brand' => (string) $product->brand_name,
                'name' => (string) $product->product_name,
                'chip' => TryOnChips::of($product),
            ])->values(),
            // "Для тебе": the pieces that suit the member's season; with no
            // season yet, the whole catalog rather than an empty grid.
            'forYouIds' => $season ? $recommendations->productIds($season) : [],
            'likedIds' => $request->user()->favorites()->pluck('product_id')->all(),
            'memberLead' => $memberLead->mint($request->user(), (string) $request->ip()),
            'photo' => $request->session()->get(self::SESSION_KEY),
            // The spec's limit is 3 "unless the config has one": the outfit
            // endpoint refuses sets above this cap.
            'maxItems' => (int) config('fitting_room.outfit.max_items', 3),
            'tryonRoutes' => [
                'generate' => route('fitting-room.generate'),
                'outfit' => route('fitting-room.generate-outfit'),
                'login' => route('login'),
                'pricing' => route('membership.pricing'),
                'favorite' => route('membership.account.favorites.store', ['product' => 0]),
            ],
        ]);
    }

    /**
     * The session's photo key while the photo is still in storage; a photo
     * swept after its 72 hours is forgotten, and step 2 asks for a new one.
     */
    private function photoKey(Request $request): ?string
    {
        $key = $request->session()->get(self::SESSION_KEY.'.key');
        if (! is_string($key) || $key === '') {
            return null;
        }

        try {
            $exists = Storage::disk((string) config('fitting_room.photo.disk'))->exists($key);
        } catch (Throwable) {
            $exists = false;
        }

        if (! $exists) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $key;
    }
}

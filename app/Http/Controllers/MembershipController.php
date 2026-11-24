<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\Product;
use App\Services\Capsules\CapsuleBuilder;
use App\Services\Catalog\CategoryChips;
use App\Services\Catalog\StylePairings;
use App\Services\Color\ColorRecommendations;
use App\Services\Credits\CreditService;
use App\Services\Generation\MemberLead;
use App\Services\Membership\AccountShell;
use App\Services\Membership\PlanCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Membership & Account. Sessions (Stage B2) and the dashboard token balance
 * (Stage B3) are real, so are looks and wardrobe; PLANS stay MOCK until
 * billing (Stage C). The design source is
 * the design hand-over; plans/tiers mirror its
 * tierData verbatim.
 *
 * Dev-only state switches (query string, validated against whitelists):
 * - ?plan=fashionista|shopaholic|couture|vip — view any plan's locks/unlimited
 *   (local/testing only, see AccountShell::planPreview()).
 */
class MembershipController extends Controller
{
    /**
     * Dashboard plan cards — copied one-to-one from the Figma "Desk_lk" frame
     * (node 1430:6601). They are a preview only: nothing is purchasable until
     * billing (Stage C), and the page says so under the grid.
     *
     * Prices, try-on counts and names match the pricing page: one list of plans.
     *
     * The words (tagline, try-ons, button, Diva's per-month price) are
     * lang/<locale>/plans.php `dashboard.<key>`.
     */
    private const DASHBOARD_PLANS = [
        ['key' => 'free', 'index' => '01', 'name' => 'FREE', 'price' => 'AED 0', 'tone' => 'light'],
        ['key' => 'fashionista', 'index' => '02', 'name' => 'Fashionista', 'price' => 'AED 39', 'tone' => 'cream'],
        ['key' => 'shopaholic', 'index' => '03', 'name' => 'Shopaholic', 'price' => 'AED 99', 'tone' => 'dark'],
        ['key' => 'couture', 'index' => '04', 'name' => 'Couture', 'price' => 'AED 269', 'tone' => 'light'],
        ['key' => 'vip', 'index' => '05', 'name' => 'Diva VIP', 'price' => 'AED 749', 'tone' => 'cream'],
    ];

    /** Share targets of the "Сподобався образ?" strip, in the frame's order. */
    private const SHARE_NETWORKS = [
        'instagram' => 'Instagram',
        'tiktok' => 'TikTok',
        'facebook' => 'Facebook',
        'linkedin' => 'LinkedIn',
        'pinterest' => 'Pinterest',
        'youtube' => 'YouTube',
        'threads' => 'Threads',
    ];

    public function __construct(
        private readonly CreditService $credits,
        private readonly AccountShell $shell,
        private readonly PlanCatalog $plans,
    ) {}

    public function pricing(): View
    {
        return view('membership.pricing', ['tiers' => $this->plans->tiers()]);
    }

    public function dashboard(Request $request, MemberLead $memberLead, ColorRecommendations $recommendations): View|RedirectResponse
    {
        // The style quiz is offered until it has been answered or skipped —
        // not only at sign-in, which a remembered session never passes
        // through again. Not when the member arrives with a piece picked
        // ("Приміряти" on the colour page): that errand comes first.
        if (! $request->has('pick') && ! ($request->user()->styleProfile?->wasOffered() ?? false)) {
            return redirect()->route('membership.account.onboarding', array_filter(['plan' => $request->query('plan')]));
        }

        $shared = $this->shared($request, 'dashboard');

        // The member's own season, while the colour engine is on: it lights up
        // the "Мої кольори" chip with the pieces that suit it.
        $season = config('features.color_analysis') ? $request->user()->colorProfile?->season : null;

        // The catalog panel shows what the feed has verified for try-on —
        // the same gate as /api/catalog — and shows ALL of it: the panel's
        // search and brand picker are the way through a few dozen pieces, and
        // a page cut would hide products the picker still counts. The "buy
        // this look" strip previews the first pieces of the same catalog: the
        // buy button still reads brand and shop link from the product row,
        // never from the template.
        $products = Product::where('flat_bg_verified', true)
            ->tryOnable()
            ->fromActiveSources()
            ->orderByDesc('id')
            ->get();

        return view('membership.dashboard', $shared + [
            'depleted' => $shared['tokensLeft'] === 0,
            'products' => $products,
            'lookProducts' => $products->take(3),
            'myColourIds' => $season ? $recommendations->productIds($season) : [],
            // ?pick= arrives from "Приміряти" on the colour page: that piece is
            // selected when the page opens, if it is in the catalog at all.
            'picked' => $products->firstWhere('id', (int) $request->query('pick'))?->id,
            // The category chips come from the feed: one per category the
            // verified products are actually filed under (CategoryChips).
            'categories' => CategoryChips::for($products),
            // The detail modal ("подробнее" on a card): what the card does not
            // show — the feed's description, every photo, the pieces to style
            // it with. Keyed by product id; the modal reads it client-side.
            'productDetails' => $this->productDetails($products),
            // "Обране": the member's hearts, the same list the wardrobe shows.
            'likedIds' => $request->user()->favorites()->pluck('product_id')->all(),
            // The try-on runs on the same endpoints as the internal page: the
            // member's owned lead and its token let /generate debit their
            // credits, and the consent versions travel with the photo upload.
            'memberLead' => $memberLead->mint($request->user(), (string) $request->ip()),
            'consentVersion' => (string) config('consent.version'),
            'termsVersion' => (string) config('terms.version'),
            'tryonRoutes' => [
                'photo' => route('fitting-room.photo.store'),
                'generate' => route('fitting-room.generate'),
                'login' => route('login'),
                // Templates for the result's buttons; the client puts the
                // finished look's generation / product id in place of 0.
                'download' => route('membership.account.looks.download', ['generation' => 0]),
                'shop' => route('membership.account.shop', ['product' => 0, 'from' => 'tryon']),
                'favorite' => route('membership.account.favorites.store', ['product' => 0]),
            ],
            'shareNetworks' => self::SHARE_NETWORKS,
            'plans' => $this->worded(self::DASHBOARD_PLANS, 'plans.dashboard'),
            'bookingUrl' => config('membership.stylist_booking_url'),
            'debugLook' => $this->debugLook($request),
        ]);
    }

    /**
     * The "Примірка" screen of the design hand-over is the phone's dashboard now
     * (membership/partials/m-tryon); its old address leads there.
     */
    public function tryOn(): RedirectResponse
    {
        return redirect()->route('membership.account.dashboard');
    }

    /**
     * Local only: /account?debug_result (or =<generation id>) opens the page on
     * step 3 with one of the member's finished looks, so the result screen can
     * be checked without a new — paid — try-on. Never anywhere but local.
     * The photo's key comes along, so "add a look" can try another piece on
     * the same, already uploaded photo.
     *
     * @return array{id: int, before: ?string, after: string, product: ?int, photo_key: ?string}|null
     */
    private function debugLook(Request $request): ?array
    {
        if (! app()->environment('local') || ! $request->has('debug_result')) {
            return null;
        }

        $generation = Generation::query()
            ->where('status', 'done')
            ->whereHas('lead', fn ($lead) => $lead->where('user_id', $request->user()->id))
            ->when((int) $request->query('debug_result'), fn ($q, $id) => $q->whereKey($id))
            ->orderByDesc('id')
            ->first();

        $after = $generation
            ? ($this->signedUrl($generation->result_keys[0] ?? null) ?? $this->firstResultUrl($generation->result_urls))
            : null;

        return $after ? [
            'id' => $generation->id,
            'before' => $this->signedUrl($generation->client_photo),
            'after' => $after,
            'product' => $generation->product_id,
            // The upload when it was recorded: a new look is processed from
            // it for its own category, not from this look's crop.
            'photo_key' => $generation->source_photo ?? $generation->client_photo,
        ] : null;
    }

    /**
     * "My Looks": the member's last 30 finished try-ons, newest first — the
     * same generations the wardrobe reads, as a plain history. Hiding a set
     * in the wardrobe does not take it out of the history. Save downloads
     * the image, Shop goes to the first piece's shop through the click log.
     *
     * Images still expire with the 72-hour photo lifecycle; a look whose
     * image is gone keeps its card with a note instead of a picture.
     */
    public function looks(Request $request): View
    {
        $generations = Generation::query()
            ->where('status', 'done')
            ->whereHas('lead', fn ($lead) => $lead->where('user_id', $request->user()->id))
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $products = Product::whereIn('id', $generations
            ->flatMap(fn (Generation $g) => $g->product_ids ?: [$g->product_id])
            ->filter()
            ->unique())
            ->get()
            ->keyBy('id');

        $looks = $generations->map(function (Generation $g) use ($products) {
            $pieces = collect($g->product_ids ?: [$g->product_id])
                ->map(fn ($id) => $products->get($id))
                ->filter()
                ->values();
            $first = $pieces->first();
            $shoppable = $pieces->first(fn (Product $p) => in_array(parse_url((string) $p->shop_url, PHP_URL_SCHEME), ['http', 'https'], true));

            return [
                'id' => $g->id,
                'brand' => $first?->brand_name,
                'name' => $first?->product_name ?? __('wardrobe.look_fallback'),
                'more' => max(0, $pieces->count() - 1),
                'date' => $g->created_at?->locale(app()->getLocale())->translatedFormat('j M'),
                // Fresh signed URLs: the ones stored at generation time expire.
                'image' => $this->signedUrl($g->result_keys[0] ?? null) ?? $this->firstResultUrl($g->result_urls),
                // Only a copy in our storage can be downloaded (LookDownloadController).
                'download' => ($g->result_keys[0] ?? null)
                    ? route('membership.account.looks.download', ['generation' => $g->id])
                    : null,
                'shop' => $shoppable ? route('membership.account.shop', ['product' => $shoppable->id, 'from' => 'looks']) : null,
            ];
        });

        return view('membership.looks', $this->shared($request, 'looks') + ['looks' => $looks]);
    }

    /**
     * "Мій гардероб" (Figma frame 1456:10104): the member's finished try-ons,
     * newest first, each with the pieces it was made of. Real data — the
     * generations behind the member's own leads — so the page is empty until
     * they have tried something on.
     *
     * NOTE: generations still expire with the 72-hour photo lifecycle, so a
     * look leaves the wardrobe when its images are deleted. "Saved forever"
     * needs a retention decision before it can be promised (PENDING.md).
     */
    public function wardrobe(Request $request): View
    {
        $shared = $this->shared($request, 'wardrobe');
        $user = $request->user();

        $generations = Generation::query()
            ->where('status', 'done')
            ->whereNull('wardrobe_hidden_at')
            ->whereHas('lead', fn ($lead) => $lead->where('user_id', $user->id))
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $productIds = $generations
            ->flatMap(fn (Generation $g) => $g->product_ids ?: [$g->product_id])
            ->filter()
            ->unique();
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        $looks = $generations->map(function (Generation $g) use ($products) {
            $hidden = array_map('intval', $g->wardrobe_hidden_product_ids ?? []);
            $pieces = collect($g->product_ids ?: [$g->product_id])
                ->reject(fn ($id) => in_array((int) $id, $hidden, true))
                ->map(fn ($id) => $products->get($id))
                ->filter()
                ->values();

            return [
                'id' => $g->id,
                'title' => $pieces->first()?->product_name ?? __('wardrobe.look_fallback'),
                // Fresh signed URLs: the ones stored at generation time expire.
                'before' => $this->signedUrl($g->client_photo),
                'after' => $this->signedUrl($g->result_keys[0] ?? null)
                    ?? $this->firstResultUrl($g->result_urls),
                'products' => $pieces,
                // Every piece taken out by the member: the set goes too.
                'emptied' => $hidden !== [] && $pieces->isEmpty(),
            ];
        })
            ->reject(fn (array $look) => $look['emptied'])
            ->values();

        // "Обране": every hearted piece, tried on or not, newest heart first.
        $favorites = Product::query()
            ->join('favorites', 'favorites.product_id', '=', 'products.id')
            ->where('favorites.user_id', $user->id)
            ->orderByDesc('favorites.created_at')
            ->orderByDesc('favorites.id')
            ->select('products.*')
            ->get();

        return view('membership.wardrobe', $shared + [
            'locked' => $shared['planData']['rank'] < 3,
            'looks' => $looks,
            'favorites' => $favorites,
            'favoriteRoute' => route('membership.account.favorites.store', ['product' => 0]),
            // The phone's opened set ends at the stylist card (Figma 1954:9893).
            'bookingUrl' => config('membership.stylist_booking_url'),
            // Templates: the client puts the set / piece id in place of 0.
            'hideRoutes' => [
                'look' => route('membership.account.wardrobe.looks.hide', ['generation' => 0]),
                'piece' => route('membership.account.wardrobe.pieces.hide', ['generation' => 0, 'product' => 0]),
            ],
            // Chips for the categories the member's own pieces — tried on or
            // hearted — are filed under.
            'categories' => CategoryChips::for($products->values()->merge($favorites)->unique('id')),
            // What "Порівняти образи" shows of each look side by side: its
            // image and its pieces, brand and shop link from the product row.
            // Nothing while the wardrobe is locked: the page carries no look.
            'compareLooks' => $shared['planData']['rank'] < 3 ? [] : $looks->map(fn (array $look) => [
                'id' => $look['id'],
                'after' => $look['after'],
                'pieces' => $look['products']->map(fn (Product $product) => [
                    'id' => $product->id,
                    'brand' => $product->brand_name,
                    'name' => $product->product_name,
                    'price' => number_format((float) $product->price_aed, 2).' AED',
                    'shop' => route('membership.account.shop', ['product' => $product->id, 'from' => 'wardrobe']),
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * Capsules (Figma 1907:9446 / 1958:10180): one capsule per occasion, each
     * the looks CapsuleBuilder puts together from the catalog in the member's
     * palette. Built from the member's season, so without one — colour
     * analysis off, or not taken yet — the page says how to get one instead
     * of showing looks picked without a palette.
     */
    public function capsules(Request $request, CapsuleBuilder $builder): View
    {
        $shared = $this->shared($request, 'capsules');
        $locked = $shared['planData']['rank'] < 3;
        [$state, $season] = $this->capsuleState($request);

        $sets = ! $locked && $state === 'ready'
            ? collect((array) config('capsules.occasions'))
                ->mapWithKeys(fn (string $occasion) => [$occasion => $builder->build($season, $occasion)])
                ->all()
            : [];

        return view('membership.capsules', $shared + [
            'locked' => $locked,
            'state' => $state,
            'season' => $season,
            'sets' => $sets,
        ]);
    }

    /**
     * One capsule (Figma 1958:10473 / 1962:8107): every piece its looks use,
     * then the looks themselves as the ways to wear them. Without the plan or
     * a season there is no capsule to open: the list says why.
     */
    public function capsule(Request $request, CapsuleBuilder $builder, string $occasion): View|RedirectResponse
    {
        $shared = $this->shared($request, 'capsules');
        [$state, $season] = $this->capsuleState($request);

        if ($shared['planData']['rank'] < 3 || $state !== 'ready') {
            return redirect()->route('membership.account.capsules', array_filter(['plan' => $request->query('plan')]));
        }

        return view('membership.capsule', $shared + [
            'occasion' => $occasion,
            'set' => $builder->build($season, $occasion),
            'favorites' => $request->user()->favorites()->pluck('product_id')->all(),
            'favoriteRoute' => route('membership.account.favorites.store', ['product' => 0]),
        ]);
    }

    /**
     * Whether capsules can be built for this member, and from which season.
     *
     * @return array{0: 'colour_off'|'no_season'|'ready', 1: ?string}
     */
    private function capsuleState(Request $request): array
    {
        if (! config('features.color_analysis')) {
            return ['colour_off', null];
        }

        $season = $request->user()->colorProfile?->season;

        return [$season === null ? 'no_season' : 'ready', $season];
    }

    public function vip(Request $request): View
    {
        return view('membership.vip', $this->shared($request, 'vip') + [
            'stylist' => [
                'name' => 'Camille Rousseau',
                'meta' => __('pages.vip.demo_meta'),
                'duration' => '0:48',
                'verdict' => __('pages.vip.demo_verdict'),
            ],
        ]);
    }

    public function settings(Request $request): View
    {
        $shared = $this->shared($request, 'settings');
        $rank = $shared['planData']['rank'];

        // Photo slots by plan: Fashionista gets 1 slot (2 locked); Shopaholic+
        // gets all 3 (2 filled on Shopaholic, 3 from Couture up). Slot 0 is the
        // active reference photo.
        $unlockedCount = $rank >= 2 ? 3 : 1;
        $filledCount = $rank >= 3 ? 3 : ($rank === 2 ? 2 : 1);
        $slots = [];
        foreach (range(0, 2) as $i) {
            $slots[] = [
                'locked' => $i >= $unlockedCount,
                'filled' => $i < $filledCount && $i < $unlockedCount,
                'active' => $i === 0,
            ];
        }

        return view('membership.settings', $shared + [
            'photoSlots' => $slots,
            'photoUpsell' => $rank === 1,
        ]);
    }

    /**
     * What the detail modal shows per piece, keyed by product id. Brand, name
     * and price repeat the card so the modal needs nothing else; the ID is
     * the feed's own (an ASIN for Amazon).
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, array<string, mixed>>
     */
    private function productDetails($products): array
    {
        $pairings = StylePairings::for($products);

        return $products->mapWithKeys(fn (Product $product) => [$product->id => [
            'id' => $product->id,
            'externalId' => $product->external_id,
            'brand' => $product->brand_name,
            'name' => $product->product_name,
            'price' => number_format((float) $product->price_aed, 2).' AED',
            // The chip it is filed under, for the result's caption on a phone.
            'category' => $product->category_label,
            'description' => $product->description(),
            'images' => $product->galleryUrls(),
            'pairs' => $pairings[$product->id] ?? [],
        ]])->all();
    }

    /**
     * A short-lived signed URL for a private R2 key, or null when there is no
     * key or the disk cannot sign (local/test disks) — the page then shows
     * its placeholder instead of a broken image.
     */
    private function signedUrl(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        try {
            return Storage::disk((string) config('fitting_room.photo.disk'))
                ->temporaryUrl($key, now()->addMinutes(30));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The first look's URL out of result_urls, which is a list of
     * {url, with_background} in production and of plain strings in older rows.
     *
     * @param  array<int, mixed>|null  $resultUrls
     */
    private function firstResultUrl(?array $resultUrls): ?string
    {
        $first = $resultUrls[0] ?? null;

        return is_array($first) ? ($first['url'] ?? null) : $first;
    }

    /**
     * Plan rows with their words put in, in the request's language: each row
     * takes lang/<locale>/<$group>.<key> on top of its own fields.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function worded(array $rows, string $group): array
    {
        return array_map(fn (array $row) => array_merge($row, (array) trans($group.'.'.$row['key'])), $rows);
    }

    /**
     * What every account page shares — plan, navigation, credit balance —
     * drawn by AccountShell so pages in other controllers get the same sidebar.
     *
     * @return array{plan: ?string, planData: array, section: string, navItems: array, tokensLeft: int}
     */
    private function shared(Request $request, string $section): array
    {
        return $this->shell->shared($request, $section);
    }
}

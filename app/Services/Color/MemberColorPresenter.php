<?php

namespace App\Services\Color;

use App\Models\ColorProfile;
use App\Models\ColorProfileImage;
use App\Models\Product;
use App\Models\Season;
use App\Models\SeasonColor;
use Illuminate\Support\Facades\Lang;

/**
 * A colour profile the way its owner sees it on the account page.
 *
 * The engine's record is an instrument reading — scores, axes, zones, the
 * white-balance method, confidence multipliers — and every one of those
 * numbers is kept OUT of this shape on purpose. A member is told four things:
 * whether the answer is ready, which season it is and what that looks like in
 * words, which colours to wear and which to leave, and, when the photo could
 * not be read, what to do differently. The runner-up is named as the nearest
 * alternative, and how sure the reading is comes as a word (low / medium /
 * high), never as the number or the margin behind it.
 *
 * Copy is Ukrainian regardless of the app locale, like the rest of the
 * account, so the same lang files serve both this page and the instrument.
 */
class MemberColorPresenter
{
    private const LOCALE = 'uk';

    /**
     * Uploaded formats a browser can draw. Anything else (HEIC from a phone)
     * is analysed just the same but not shown back, because it cannot be.
     *
     * @var list<string>
     */
    private const BROWSER_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(private readonly ColorRecommendations $recommendations) {}

    /**
     * @return array<string, mixed>
     */
    public function present(ColorProfile $profile): array
    {
        $status = $this->status($profile);
        $season = $this->season($profile);
        $shelf = $season ? $this->shelf($profile, $season['slug']) : ['products' => [], 'filtered_by' => []];

        return [
            'id' => $profile->id,
            'status' => $status,
            'pending' => $status === 'pending',
            'created_at' => $profile->created_at?->toIso8601String(),
            'season' => $season,
            'alternative' => $this->alternative($profile),
            'confidence_level' => $this->confidenceLevel($profile),
            'palette' => $season ? $this->palette($season['slug']) : [],
            'products' => $shelf['products'],
            'products_filtered_by' => $shelf['filtered_by'],
            'reject_message' => $this->rejectMessage($profile),
            'photo' => $this->photo($profile),
            'photo_hours' => (int) config('privacy.photo_hard_delete_hours'),
        ];
    }

    /**
     * pending | ok | unplaced | rejected | failed.
     *
     * "unplaced" is the engine measuring the photo and still placing no
     * season — a real outcome the page must word, not an error.
     */
    private function status(ColorProfile $profile): string
    {
        if ($profile->isPending()) {
            return 'pending';
        }

        if ($profile->status === ColorProfile::STATUS_OK) {
            return $profile->rankedSeasons() === [] ? 'unplaced' : 'ok';
        }

        return $profile->status === ColorProfile::STATUS_REJECTED ? 'rejected' : 'failed';
    }

    /**
     * The member's own season, with what only it carries: its character in
     * three words and what to do with it in a wardrobe.
     *
     * @return array{slug: string, name: string, description: string|null, traits: list<string>, tips: list<string>}|null
     */
    private function season(ColorProfile $profile): ?array
    {
        $slugs = array_keys($profile->rankedSeasons());

        if ($profile->status !== ColorProfile::STATUS_OK || $slugs === []) {
            return null;
        }

        return $this->seasonWords($slugs[0]) + [
            'traits' => $this->lines('seasons.traits.'.$slugs[0]),
            'tips' => $this->lines('seasons.tips.'.$slugs[0]),
        ];
    }

    /**
     * The runner-up, always, as "the nearest alternative": the engine ranks
     * every season and the second is real information — whether it is a
     * close call is what confidence_level says.
     *
     * @return array{slug: string, name: string, description: string|null}|null
     */
    private function alternative(ColorProfile $profile): ?array
    {
        $slugs = array_keys($profile->rankedSeasons());

        if ($profile->status !== ColorProfile::STATUS_OK || count($slugs) < 2) {
            return null;
        }

        return $this->seasonWords($slugs[1]);
    }

    /**
     * How sure the reading is, in a word — low, medium or high — never the
     * number behind it. "low" is the engine's own verdict (its low-confidence
     * flag); medium and high split the rest at config's thresholds.
     */
    private function confidenceLevel(ColorProfile $profile): ?string
    {
        if ($profile->status !== ColorProfile::STATUS_OK || $profile->rankedSeasons() === []) {
            return null;
        }

        $levels = (array) config('color_analysis.confidence_levels');
        $confidence = $profile->confidence;

        if ($profile->hasLowConfidence() || $confidence === null || $confidence < (float) $levels['medium']) {
            return 'low';
        }

        return $confidence >= (float) $levels['high'] ? 'high' : 'medium';
    }

    /**
     * @return array{slug: string, name: string, description: string|null}
     */
    private function seasonWords(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => $this->text('seasons.names.'.$slug) ?? ucwords(str_replace('_', ' ', $slug)),
            'description' => $this->text('seasons.descriptions.'.$slug),
        ];
    }

    /**
     * The season's colours in the four groups, as swatches. The hex is what a
     * screen shows and the ink is what reads on it; the L*a*b* behind them
     * stays in the row.
     *
     * @return list<array{name: string, label: string, hint: string|null, avoid: bool, colors: list<array{hex: string, ink: string}>}>
     */
    private function palette(string $slug): array
    {
        $season = Season::with('colors')->where('slug', $slug)->first();

        if ($season === null) {
            return [];
        }

        $palette = $season->palette();
        $groups = [];

        foreach (SeasonColor::GROUPS as $name) {
            $colors = $palette[$name] ?? null;

            if ($colors === null || $colors->isEmpty()) {
                continue;
            }

            $groups[] = [
                'name' => $name,
                'label' => $this->text('seasons.groups.'.$name) ?? ucfirst($name),
                'hint' => $this->text('seasons.group_hints.'.$name),
                'avoid' => ! (bool) $colors->first()->is_recommended,
                'colors' => $colors->map(fn (SeasonColor $color) => [
                    'hex' => $color->hex,
                    'ink' => $color->readableInk(),
                ])->values()->all(),
            ];
        }

        return $groups;
    }

    /**
     * Catalog pieces in the season's colours, narrowed by the owner's style
     * quiz. Each carries what a card needs and two ways on: buy (through the
     * click counter, to the shop) and try on (the dashboard, piece picked).
     *
     * @return array{products: list<array<string, mixed>>, filtered_by: list<string>}
     */
    private function shelf(ColorProfile $profile, string $season): array
    {
        $found = $this->recommendations->forSeason($season, $profile->user?->styleProfile?->answers);

        return [
            'products' => $found['products']->map(fn (Product $product) => [
                'id' => $product->id,
                'brand' => $product->brand_name,
                'name' => $product->product_name,
                'price' => number_format((float) $product->price_aed, 2).' AED',
                'image' => $product->image_flat_url,
                'buy_url' => route('membership.account.shop', ['product' => $product->id, 'from' => 'color']),
                'try_url' => route('membership.account.dashboard', ['pick' => $product->id]).'#catalog-grid',
            ])->values()->all(),
            'filtered_by' => $found['filtered_by'],
        ];
    }

    /**
     * What to do differently, in the person's language. Only for a photo the
     * engine looked at and declined; a failure on our side says nothing about
     * the photo and gets a sentence of its own on the page.
     */
    private function rejectMessage(ColorProfile $profile): ?string
    {
        if ($profile->status !== ColorProfile::STATUS_REJECTED || $profile->reject_reason === null) {
            return null;
        }

        return $this->text('color_analysis.'.$profile->reject_reason)
            ?? $this->text('color_analysis.unsupported_format');
    }

    /**
     * Whether the uploaded photo can be shown back, and until when it exists.
     *
     * @return array{available: bool, expires_at: string|null}
     */
    private function photo(ColorProfile $profile): array
    {
        $image = $profile->images()->where('kind', ColorProfileImage::KIND_SOURCE)->first();

        if ($image === null) {
            return ['available' => false, 'expires_at' => null];
        }

        $extension = strtolower(pathinfo($image->path, PATHINFO_EXTENSION));

        return [
            'available' => in_array($extension, self::BROWSER_IMAGE_EXTENSIONS, true)
                && ($image->expires_at === null || $image->expires_at->isFuture()),
            'expires_at' => $image->expires_at?->toIso8601String(),
        ];
    }

    private function text(string $key): ?string
    {
        $value = Lang::get($key, [], self::LOCALE);

        return is_string($value) && $value !== $key ? $value : null;
    }

    /**
     * A list of strings from the lang files; empty when the key is missing,
     * so a season without copy loses a block rather than showing its key.
     *
     * @return list<string>
     */
    private function lines(string $key): array
    {
        $value = Lang::get($key, [], self::LOCALE);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}

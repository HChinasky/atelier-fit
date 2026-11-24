<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreColorProfileRequest;
use App\Models\ColorProfile;
use App\Models\ColorProfileImage;
use App\Models\Season;
use App\Models\SeasonColor;
use App\Services\Color\ColorProfileIntake;
use App\Services\Color\LabSwatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The internal colour-analysis tool: upload a photo, see what the engine
 * measured on it.
 *
 * Admin-only, behind a feature flag, and not linked from anywhere on the site.
 * It exists to check the engine on real faces.
 *
 * Everything it shows about a season is a DRAFT and the page says so in a banner
 * that cannot be dismissed: the classifier's anchor points were reasoned rather
 * than measured against labelled faces, and the palettes were generated rather
 * than chosen by a stylist. The banner stays until both of those stop being
 * true, which is what `classifier.draft` and `season_colors.is_draft` are for.
 *
 * Every route here answers 404 to anyone who may not have it: the flag, the role
 * and the ownership check all give the same answer, so probing which paths
 * refuse differently cannot map the area out.
 *
 * Privacy: the photo and the rendered views go to private storage under the SAME
 * prefix as try-on photos, which is what puts them on the existing 60-hour purge
 * with nothing new to maintain. They are served only by {@see image()}, which
 * checks the same flag, session and role as every other route here — never by a
 * public or pre-signed URL. Nothing here logs a photo, a key or a filename.
 */
class ColorAnalysisController extends Controller
{
    /**
     * The three skin sub-zones the pooled skin sample is the median of.
     *
     * @var list<string>
     */
    private const SKIN_SUBZONES = ['left_cheek', 'right_cheek', 'forehead'];

    /**
     * GET /admin/color — the form, this admin's recent profiles, and (when
     * asked for) one of them opened.
     */
    public function index(Request $request): View
    {
        $recent = ColorProfile::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit((int) config('color_analysis.recent_limit'))
            ->get();

        $selected = $request->integer('profile') > 0
            ? $recent->firstWhere('id', $request->integer('profile'))
            : null;

        return view('admin.color.index', [
            'recent' => $recent->map(fn (ColorProfile $p) => $this->summary($p))->all(),
            'selected' => $selected ? $this->payload($selected) : null,
            'lightings' => ColorProfile::LIGHTINGS,
            'quizOptions' => [
                'metal' => ['gold', 'silver', 'both'],
                'tanning' => ['burns', 'tans', 'both'],
                'veins' => ['blue', 'green', 'mixed'],
            ],
        ]);
    }

    /**
     * POST /admin/color — park the photo, queue the analysis, hand back the
     * profile so the page can start polling.
     */
    public function store(StoreColorProfileRequest $request, ColorProfileIntake $intake): JsonResponse
    {
        // The same intake as the member page: where a face is stored and for
        // how long is decided once. What differs is what the row records —
        // the calibration handle and light, typed off the sheet next to the
        // camera — and that the instrument asks for the debug pictures.
        $profile = $intake->start(
            $request->user(),
            $request->file('photo'),
            [
                'quiz' => $request->quiz(),
                'person_ref' => $request->input('person_ref') ?: null,
                'lighting' => $request->input('lighting') ?: null,
            ],
            debug: true,
        );

        return response()->json($this->payload($profile), 201);
    }

    /**
     * GET /admin/color/{profile} — the current state, for polling.
     */
    public function show(Request $request, ColorProfile $profile): JsonResponse
    {
        $this->own($request, $profile);

        return response()->json($this->payload($profile));
    }

    /**
     * GET /admin/color/{profile}/image/{kind} — one stored image, streamed.
     *
     * The only way these bytes reach a browser. A missing object is a 404, not
     * an error: after the purge the row and the object are both gone, and the
     * page is expected to cope with a picture that has expired.
     */
    public function image(Request $request, ColorProfile $profile, string $kind): StreamedResponse
    {
        $this->own($request, $profile);

        $image = $profile->images()->where('kind', $kind)->first();
        abort_if($image === null, 404);

        $disk = Storage::disk($image->disk);
        abort_unless($disk->exists($image->path), 404);

        return $disk->response($image->path, null, [
            // Never cached by a shared proxy: this is somebody's face behind a
            // session check, and a cache would serve it without one.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * An admin may only see their own uploads. Not a secrecy measure between
     * administrators so much as a guard against one tool showing another
     * person's face for no reason anybody asked for.
     */
    private function own(Request $request, ColorProfile $profile): void
    {
        abort_unless($profile->user_id === $request->user()->id, 404);
    }

    /**
     * The compact shape the recent list is drawn from.
     *
     * @return array<string, mixed>
     */
    private function summary(ColorProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'status' => $profile->status,
            'reject_reason' => $profile->reject_reason,
            'reject_message' => $this->rejectMessage($profile),
            'created_at' => $profile->created_at?->toIso8601String(),
            'created_for_humans' => $profile->created_at?->diffForHumans(),
        ];
    }

    /**
     * Everything the result panel needs, already resolved: no measurement is
     * recomputed in the browser, and no colour maths happens there.
     *
     * @return array<string, mixed>
     */
    private function payload(ColorProfile $profile): array
    {
        $images = $profile->images()
            ->where('kind', '!=', ColorProfileImage::KIND_SOURCE)
            ->pluck('kind')
            ->values()
            ->all();

        return $this->summary($profile) + [
            'pending' => $profile->isPending(),
            'error' => $profile->error,
            'algo_version' => $profile->algo_version,
            'person_ref' => $profile->person_ref,
            'lighting' => $profile->lighting,
            'target_calibrated' => (bool) $profile->target_calibrated,
            'season' => $profile->season,
            'confidence' => $profile->confidence,
            'classification' => $this->classification($profile),
            'axes' => $this->axes($profile),
            'contrast' => $this->contrast($profile),
            'palettes' => $this->palettes($profile),
            'quiz' => $profile->quiz,
            'white_balance' => $this->whiteBalance($profile),
            'zones' => $this->zones($profile),
            'images' => $images,
            'has_source' => $profile->images()
                ->where('kind', ColorProfileImage::KIND_SOURCE)
                ->exists(),
            // The uploaded file is served as it arrived, and a phone sends
            // HEIC, which no browser renders. The engine hands back a decoded
            // preview of the very frame it measured; when there is one, that is
            // what "as uploaded" should show.
            'has_preview' => in_array('source_preview', $images, true),
        ];
    }

    /**
     * The season block: the top two with their names and scores, how much to
     * trust it, and the arithmetic behind that number.
     *
     * Null when the engine placed no season — which is a real outcome, not an
     * error, and the page says which.
     *
     * @return array<string, mixed>|null
     */
    private function classification(ColorProfile $profile): ?array
    {
        $ranked = $profile->rankedSeasons();

        if ($ranked === []) {
            return null;
        }

        $info = $profile->classifier ?? [];
        $slugs = array_keys($ranked);

        return [
            'seasons' => array_map(fn (string $slug) => [
                'slug' => $slug,
                'name' => $this->seasonName($slug),
                'description' => $this->seasonDescription($slug),
                'score' => $ranked[$slug],
            ], $slugs),
            'confidence' => $profile->confidence,
            'version' => $info['version'] ?? null,
            'draft' => $profile->isDraftClassification(),
            'low_confidence' => $profile->hasLowConfidence(),
            // Why the confidence is what it is. A number a stylist is asked to
            // act on has to be arguable, and it cannot be argued with unless
            // the argument is on the page.
            'confidence_parts' => $this->confidenceParts($info['confidence'] ?? null),
            'exclusions' => $this->exclusions($info['exclusions'] ?? []),
        ];
    }

    /**
     * The three axes as bars: where the marker sits, and what each end means.
     *
     * The marker is a percentage across the bar rather than a raw -1..1 value
     * because the browser should not be doing arithmetic on a measurement.
     *
     * @return list<array<string, mixed>>
     */
    private function axes(ColorProfile $profile): array
    {
        $measured = $profile->axes ?? [];
        // A refused photo never reached the features stage at all. Saying
        // "every feature behind this axis was excluded" would describe a
        // measurement that was attempted and failed, which is a different
        // thing and sends the reader looking for a mask problem.
        $rejected = $profile->status === ColorProfile::STATUS_REJECTED;
        $axes = [];

        foreach (['temperature', 'depth', 'chroma'] as $name) {
            $value = $measured[$name] ?? null;
            $ends = (array) __('seasons.axis_ends.'.$name);

            $axes[] = [
                'key' => $name,
                'label' => __('seasons.axes.'.$name),
                'value' => is_numeric($value) ? (float) $value : null,
                'position' => is_numeric($value)
                    ? round((((float) $value) + 1.0) / 2.0 * 100.0, 2)
                    : null,
                'low' => $ends[0] ?? $name,
                'high' => $ends[1] ?? $name,
                'missing_text' => $rejected ? __('seasons.axis_not_computed') : null,
            ];
        }

        return $axes;
    }

    /**
     * Contrast as a number rather than a bar.
     *
     * It is not one of the three axes and drawing it like one would say it was.
     * It runs 0..1 in one direction and it FEEDS the chroma axis, so it belongs
     * beside them with its own caption rather than among them.
     *
     * @return array<string, mixed>|null
     */
    private function contrast(ColorProfile $profile): ?array
    {
        $contrast = $profile->contrast ?? [];
        $value = $contrast['value'] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        return [
            'value' => (float) $value,
            'hint' => __('seasons.contrast_hint'),
            'hair_skin' => $contrast['hair_skin'] ?? null,
            'iris_sclera' => $contrast['iris_sclera'] ?? null,
            'eye_skin' => $contrast['eye_skin'] ?? null,
        ];
    }

    /**
     * The palette for each season the engine ranked, grouped as it is shown.
     *
     * Both of the top two, so the page can offer a toggle: for anybody sitting
     * on a boundary the runner-up is a real call, and hiding it would make a
     * thin margin look like a decision.
     *
     * Missing rows are not an error. The seeder is a command somebody runs, and
     * a page that exploded because nobody had run it would be worse than a page
     * that says so.
     *
     * @return list<array<string, mixed>>
     */
    private function palettes(ColorProfile $profile): array
    {
        $slugs = array_keys($profile->rankedSeasons());

        if ($slugs === []) {
            return [];
        }

        $seasons = Season::with('colors')->whereIn('slug', $slugs)->get()->keyBy('slug');

        return array_values(array_filter(array_map(function (string $slug) use ($seasons) {
            $season = $seasons->get($slug);

            if ($season === null || $season->colors->isEmpty()) {
                return null;
            }

            return [
                'slug' => $slug,
                'name' => $this->seasonName($slug),
                'is_draft' => $season->isDraft(),
                'groups' => $this->paletteGroups($season),
            ];
        }, $slugs)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paletteGroups(Season $season): array
    {
        $palette = $season->palette();
        $groups = [];

        foreach (SeasonColor::GROUPS as $name) {
            $colors = $palette[$name] ?? null;

            if ($colors === null || $colors->isEmpty()) {
                continue;
            }

            $groups[] = [
                'name' => $name,
                'label' => __('seasons.groups.'.$name),
                'hint' => __('seasons.group_hints.'.$name),
                'is_recommended' => (bool) $colors->first()->is_recommended,
                'colors' => $colors->map(fn (SeasonColor $color) => [
                    'hex' => $color->hex,
                    'ink' => $color->readableInk(),
                    // The LAB goes on the tooltip beside the hex: the hex is
                    // what a screen shows, the LAB is what was measured, and
                    // neither is useful here without the other.
                    'title' => sprintf(
                        '%s · L* %.1f a* %.1f b* %.1f',
                        $color->hex,
                        $color->lab_l,
                        $color->lab_a,
                        $color->lab_b,
                    ),
                    'is_draft' => $color->is_draft,
                ])->all(),
            ];
        }

        return $groups;
    }

    /**
     * The confidence multipliers, labelled.
     *
     * @param  mixed  $breakdown
     * @return list<array<string, mixed>>
     */
    private function confidenceParts($breakdown): array
    {
        if (! is_array($breakdown)) {
            return [];
        }

        $parts = [];

        // The raw gap first, then what the curve made of it. They are different
        // numbers: the live frame had two seasons tied at 0.1445 — a gap of
        // 0.000 — under a label reading "how far the first season is ahead of
        // the second: 0.150", which was the curve's floor wearing the gap's name.
        foreach (['margin', 'from_margin', 'features_factor', 'quality_factor', 'final'] as $key) {
            if (isset($breakdown[$key]) && is_numeric($breakdown[$key])) {
                $parts[] = [
                    'key' => $key,
                    'label' => __('seasons.confidence.'.$key),
                    'value' => (float) $breakdown[$key],
                ];
            }
        }

        return $parts;
    }

    /**
     * Every feature the classifier could not use, in words.
     *
     * Falls back to the raw slug rather than to silence, as the zone reasons
     * and the reject copy do: a reason the engine grew and nobody has
     * translated yet must still appear.
     *
     * @param  mixed  $exclusions
     * @return list<array<string, mixed>>
     */
    private function exclusions($exclusions): array
    {
        if (! is_array($exclusions)) {
            return [];
        }

        return array_values(array_map(function ($item): array {
            $reason = is_array($item) ? ($item['reason'] ?? '') : '';
            $key = 'seasons.exclusions.'.$reason;
            $text = __($key);

            return [
                'axis' => is_array($item) ? ($item['axis'] ?? null) : null,
                'feature' => is_array($item) ? ($item['feature'] ?? null) : null,
                'reason' => $reason,
                'reason_text' => is_string($text) && $text !== $key ? $text : $reason,
            ];
        }, $exclusions));
    }

    private function seasonName(string $slug): string
    {
        $key = 'seasons.names.'.$slug;
        $name = __($key);

        return is_string($name) && $name !== $key
            ? $name
            : ucwords(str_replace('_', ' ', $slug));
    }

    private function seasonDescription(string $slug): ?string
    {
        $key = 'seasons.descriptions.'.$slug;
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : null;
    }

    /**
     * What happened about the light, said in a sentence.
     *
     * "method: none" cannot be read on its own — it covers a deployment that
     * never wanted correction, a frame whose eyes were unusable, and one whose
     * background was too dark to estimate from. Only the middle one is worth
     * retaking a photo over, so the page has to say which it was.
     *
     * @return array<string, mixed>|null
     */
    private function whiteBalance(ColorProfile $profile): ?array
    {
        $balance = $profile->white_balance;

        if (! is_array($balance)) {
            return null;
        }

        $applied = ($balance['method'] ?? 'none') !== 'none';
        $reason = $balance['reason'] ?? null;

        // Older profiles predate the two new fields. Absence is not "on": the
        // engine's own default is off, and guessing the other way would put a
        // claim on the page that nothing supports.
        $enabled = (bool) ($balance['enabled'] ?? false);

        return $balance + [
            'enabled' => $enabled,
            'applied' => $applied,
            'summary' => $this->whiteBalanceSummary($applied, $enabled, $reason),
        ];
    }

    private function whiteBalanceSummary(bool $applied, bool $enabled, mixed $reason): string
    {
        if ($applied) {
            return __('color_white_balance.applied');
        }

        if (! $enabled) {
            return __('color_white_balance.off');
        }

        // Switched on, nothing applied, and no reason given: the photo was
        // refused before the step ran at all. Saying "off" here would be a flat
        // contradiction of the `enabled` field next to it.
        if (! is_string($reason) || $reason === '') {
            return __('color_white_balance.not_reached');
        }

        // Falls back to the raw slug rather than to silence, as every other
        // engine-supplied reason on this page does.
        $key = 'color_white_balance.'.$reason;
        $text = __($key);

        return is_string($text) && $text !== $key
            ? $text
            : __('color_white_balance.not_reached').' ('.$reason.')';
    }

    /**
     * Each measured zone as a row: the numbers, plus a swatch to look at.
     *
     * The swatch is a convenience, not a measurement — see {@see LabSwatch}. The
     * L*a*b* printed beside it is the truth, which is why both are always shown
     * together and never one without the other.
     *
     * @return list<array<string, mixed>>
     */
    private function zones(ColorProfile $profile): array
    {
        $samples = $profile->samples ?? [];
        $parts = $samples['skin_parts'] ?? [];
        // The engine reports each eye on its own. It did not always, and the
        // page showed the consequence: a row per side, never a sample to put in
        // it, and no reason either when the side had passed — "Iris, left — not
        // measured", with nothing to act on.
        $iris = $samples['iris_parts'] ?? [];
        $sclera = $samples['sclera_parts'] ?? [];

        $ordered = [
            ['key' => 'skin', 'label' => 'Skin (pooled)', 'sample' => $samples['skin'] ?? null],
            ['key' => 'left_cheek', 'label' => "Left cheek (subject's)", 'sample' => $parts['left_cheek'] ?? null],
            ['key' => 'right_cheek', 'label' => "Right cheek (subject's)", 'sample' => $parts['right_cheek'] ?? null],
            ['key' => 'forehead', 'label' => 'Forehead', 'sample' => $parts['forehead'] ?? null],
            ['key' => 'hair', 'label' => 'Hair', 'sample' => $samples['hair'] ?? null],
            ['key' => 'iris', 'label' => 'Iris', 'sample' => $samples['iris'] ?? null],
            ['key' => 'iris_left', 'label' => "Iris, left (subject's)", 'sample' => $iris['left'] ?? null],
            ['key' => 'iris_right', 'label' => "Iris, right (subject's)", 'sample' => $iris['right'] ?? null],
            ['key' => 'sclera', 'label' => 'Sclera', 'sample' => $samples['sclera'] ?? null],
            ['key' => 'sclera_left', 'label' => "Sclera, left (subject's)", 'sample' => $sclera['left'] ?? null],
            ['key' => 'sclera_right', 'label' => "Sclera, right (subject's)", 'sample' => $sclera['right'] ?? null],
        ];

        $statuses = $profile->zones ?? [];
        // How many sub-zones the pooled skin figure is actually the median of.
        // The engine pools the sub-zones that are still a measurement and drops
        // the rest, so "Skin (pooled)" beside three sub-zone rows, one of them
        // blown, reads as an average of all three. It is not.
        $pooled = $this->skinSubzones($parts, $statuses);

        return array_map(function (array $row) use ($statuses, $pooled): array {
            $sample = is_array($row['sample']) ? $row['sample'] : null;
            $status = $statuses[$row['key']] ?? null;
            $usable = is_array($status) ? ($status['usable'] ?? null) : null;
            $excluded = $sample !== null && $usable === false;

            return [
                'key' => $row['key'],
                'label' => $row['label'],
                'measured' => $sample !== null,
                // Measured, and then not measured WITH — an overexposed cheek is
                // the case that produced this. Its numbers are the evidence and
                // stay on the page, but a plain row of them says the aggregate
                // above was computed from them, and the aggregate was not. The
                // rule is the engine's verdict, not the reason behind it: any
                // reason that makes a zone unusable annotates its row the same
                // way.
                'excluded' => $excluded,
                // Only on the pooled skin row, and only when there is a pooled
                // figure to qualify.
                'pooled_from' => $row['key'] === 'skin' && $sample !== null ? $pooled : null,
                // A zone with no sample still gets a row. "Sclera: —" tells a
                // reviewer nothing about whether to retake the photo, fix a
                // threshold or fix a mask; the reason tells them which.
                'reason' => is_array($status) ? ($status['reason'] ?? null) : null,
                // Never blank. A row with no numbers and no reason is the one
                // state a reviewer cannot act on, so an unexplained gap says
                // so rather than saying nothing.
                'reason_text' => $this->zoneReason($status)
                    ?? match (true) {
                        $sample === null => __('color_zones.unexplained'),
                        $excluded => __('color_zones.unexplained_exclusion'),
                        default => null,
                    },
                'L' => $sample['L'] ?? null,
                'a' => $sample['a'] ?? null,
                'b' => $sample['b'] ?? null,
                'C' => $sample['C'] ?? null,
                'h' => $sample['h'] ?? null,
                'pixels' => $sample['pixels'] ?? null,
                'swatch' => $sample === null
                    ? null
                    : LabSwatch::hex($sample['L'] ?? null, $sample['a'] ?? null, $sample['b'] ?? null),
            ];
        }, $ordered);
    }

    /**
     * How many sub-zones the pooled skin sample was measured over, out of three.
     *
     * The engine's rule, restated: a sub-zone counts when it produced a sample
     * and the engine did not then rule it out. That is the same set it takes the
     * median of, so the count on the page is the count behind the number.
     *
     * @param  array<string, mixed>  $parts
     * @param  array<string, mixed>  $statuses
     * @return array{used: int, of: int}
     */
    private function skinSubzones(array $parts, array $statuses): array
    {
        $used = 0;

        foreach (self::SKIN_SUBZONES as $name) {
            $status = $statuses[$name] ?? null;
            $excluded = is_array($status) && ($status['usable'] ?? null) === false;

            if (is_array($parts[$name] ?? null) && ! $excluded) {
                $used++;
            }
        }

        return ['used' => $used, 'of' => count(self::SKIN_SUBZONES)];
    }

    /**
     * A zone's failure reason, in words.
     *
     * Falls back to the raw slug for the same reason the reject copy does: a
     * reason the engine grew and nobody has translated yet must still appear,
     * because a blank is the one thing that cannot be acted on.
     *
     * @param  array<string, mixed>|null  $status
     */
    private function zoneReason(?array $status): ?string
    {
        $reason = is_array($status) ? ($status['reason'] ?? null) : null;
        if (! is_string($reason) || $reason === '') {
            return null;
        }

        $key = 'color_zones.'.$reason;
        $message = __($key);

        return is_string($message) && $message !== $key ? $message : $reason;
    }

    /**
     * The reject reason as something a person can act on.
     *
     * Falls back to the raw slug rather than to silence: a reason the engine
     * grew and we have not translated yet should still be visible on the page,
     * because "rejected, no explanation" is the one thing that cannot be acted
     * on at all.
     */
    private function rejectMessage(ColorProfile $profile): ?string
    {
        if ($profile->reject_reason === null) {
            return null;
        }

        $key = 'color_analysis.'.$profile->reject_reason;
        $message = __($key);

        return is_string($message) && $message !== $key ? $message : $profile->reject_reason;
    }
}

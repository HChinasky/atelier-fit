<?php

namespace App\Http\Controllers\Stylist;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreColorProfileLabelRequest;
use App\Models\ColorProfile;
use App\Models\ColorProfileImage;
use App\Models\ColorProfileLabel;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The labelling screen: what a stylist says a face is, beside what the engine
 * measured.
 *
 * Its own area rather than a page bolted onto /admin/color, because the two
 * answer to different roles. An administrator uploads photos and reads
 * measurements; a stylist labels, and needs nothing else — not the upload form,
 * not the queue configuration, not the rest of the site. Two surfaces make that
 * a fact about the routes rather than a promise in a template.
 *
 * **A stylist sees every labellable profile, not only their own uploads.** That
 * is the opposite of the rule on /admin/color, and deliberately so: the whole
 * job is to judge faces somebody else photographed. It is also the one genuinely
 * new privacy exposure in this feature —
 *
 * Open question: a stylist is a third party looking at a member's photo, which
 * is a separate purpose of processing from "analyse my colours for me" and may
 * need its own line in the consent. Flagged here, not implemented.
 *
 * The photo is shown while it exists and disappears with the ordinary purge.
 * Nothing here extends its life: a stylist labels from the photo and from what
 * they know of the person, and once the images are gone the label and the
 * measurements are still a complete row of the dataset.
 */
class ColorLabelController extends Controller
{
    /**
     * GET /stylist/color — everything waiting to be judged.
     */
    public function index(Request $request): View
    {
        $stylist = $request->user();

        return view('stylist.color.index', [
            'profiles' => $this->queue($request, $stylist),
            'counts' => $this->counts($stylist),
            'filters' => $this->filters($request),
            'options' => $this->options(),
        ]);
    }

    /**
     * GET /stylist/color/guide — how to label.
     *
     * Rendered from docs/stylist-colour-labelling.md rather than duplicated
     * into a template. One copy of the text: a page and a document that drift
     * apart are worse than either alone, and the repository copy is the one a
     * reviewer will read.
     */
    public function guide(): View
    {
        $path = base_path('docs/stylist-colour-labelling.md');
        abort_unless(is_file($path), 404);

        return view('stylist.color.guide', [
            'body' => Str::markdown((string) file_get_contents($path)),
        ]);
    }

    /**
     * GET /stylist/color/{profile} — one face, and the form.
     */
    public function show(Request $request, ColorProfile $profile): View
    {
        $this->labellable($profile);

        return view('stylist.color.label', [
            'profile' => $this->payload($profile, $request->user()),
            'options' => $this->options(),
        ]);
    }

    /**
     * POST /stylist/color/{profile} — save this stylist's verdict.
     *
     * Their own and only their own. The key is (profile, signed-in user), so
     * editing is always an edit of one's own row and can never become an edit
     * of somebody else's, whatever the request says.
     */
    public function store(
        StoreColorProfileLabelRequest $request,
        ColorProfile $profile,
    ): RedirectResponse {
        $this->labellable($profile);

        ColorProfileLabel::updateOrCreate(
            ['color_profile_id' => $profile->id, 'user_id' => $request->user()->id],
            $request->label(),
        );

        // Deliberately NOT touching $profile->season. That is the engine's
        // answer; this is a person's. Calibration is the comparison of the two,
        // so the moment one overwrites the other there is nothing to compare.

        return redirect()
            ->route('stylist.color.index')
            ->with('labelled', $profile->id);
    }

    /**
     * GET /stylist/color/{profile}/image/{kind} — the photo, while it lasts.
     *
     * The only path by which a stored image reaches a stylist's browser, and it
     * checks the same flag, session and role as every other route here. Never a
     * public or pre-signed URL.
     */
    public function image(
        Request $request,
        ColorProfile $profile,
        string $kind,
    ): StreamedResponse {
        $this->labellable($profile);

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
     * Only a measured profile can be labelled.
     *
     * A refusal has no measurements to pair a verdict with, so a label on one
     * would be a row the report has to skip. 404 rather than a message, in
     * keeping with everything else in this area.
     */
    private function labellable(ColorProfile $profile): void
    {
        abort_unless($profile->status === ColorProfile::STATUS_OK, 404);
    }

    /**
     * The queue: measured profiles, newest first, with this stylist's own label
     * when there is one.
     *
     * @return list<array<string, mixed>>
     */
    private function queue(Request $request, User $stylist): array
    {
        $filters = $this->filters($request);

        $query = ColorProfile::query()
            ->where('status', ColorProfile::STATUS_OK)
            ->with(['labels' => fn ($q) => $q->where('user_id', $stylist->id)])
            ->latest();

        if ($filters['labelled'] === 'yes') {
            $query->whereHas('labels', fn ($q) => $q->where('user_id', $stylist->id));
        } elseif ($filters['labelled'] === 'no') {
            $query->whereDoesntHave('labels', fn ($q) => $q->where('user_id', $stylist->id));
        }

        if ($filters['low_confidence'] === 'yes') {
            $query->whereRaw("(classifier->>'low_confidence')::boolean is true");
        }

        if ($filters['person_ref'] !== null) {
            $query->where('person_ref', $filters['person_ref']);
        }

        $profiles = $query->limit((int) config('color_analysis.recent_limit') * 5)->get();

        $rows = $profiles->map(fn (ColorProfile $p) => $this->row($p, $stylist))->all();

        // Agreement is a property of the pair, so it can only be filtered once
        // both halves are in hand — after the query, not inside it.
        if ($filters['agreement'] !== 'any') {
            $wanted = $filters['agreement'] === 'match';
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => $row['agrees'] !== null && $row['agrees'] === $wanted,
            ));
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ColorProfile $profile, User $stylist): array
    {
        $label = $profile->labelBy($stylist);
        $engine = $profile->season;

        return [
            'id' => $profile->id,
            'created_for_humans' => $profile->created_at?->diffForHumans(),
            'person_ref' => $profile->person_ref,
            'lighting' => $profile->lighting,
            'engine_season' => $engine,
            'engine_confidence' => $profile->confidence,
            'low_confidence' => $profile->hasLowConfidence(),
            'label_season' => $label?->season,
            'label_confidence' => $label?->stylist_confidence,
            'labelled' => $label !== null,
            // Null when there is nothing to compare, which is not the same as
            // "they disagree" and must not filter as though it were.
            'agrees' => $label === null || $engine === null ? null : $label->season === $engine,
            'has_preview' => $profile->images()
                ->whereIn('kind', ['source_preview', ColorProfileImage::KIND_SOURCE])
                ->exists(),
        ];
    }

    /**
     * How much is done and how much is left. The only number a person labelling
     * a long queue actually wants.
     *
     * @return array<string, int>
     */
    private function counts(User $stylist): array
    {
        $total = ColorProfile::where('status', ColorProfile::STATUS_OK)->count();
        $mine = ColorProfileLabel::where('user_id', $stylist->id)->count();

        return ['total' => $total, 'labelled' => $mine, 'remaining' => max(0, $total - $mine)];
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $oneOf = static fn (string $value, array $allowed, string $fallback) => in_array(
            $value,
            $allowed,
            true,
        ) ? $value : $fallback;

        $person = trim((string) $request->query('person_ref', ''));

        return [
            'labelled' => $oneOf((string) $request->query('labelled', 'no'), ['yes', 'no', 'any'], 'no'),
            'agreement' => $oneOf(
                (string) $request->query('agreement', 'any'),
                ['match', 'differ', 'any'],
                'any',
            ),
            'low_confidence' => $oneOf(
                (string) $request->query('low_confidence', 'any'),
                ['yes', 'any'],
                'any',
            ),
            'person_ref' => $person === '' ? null : $person,
        ];
    }

    /**
     * Everything the labelling form needs, already resolved.
     *
     * @return array<string, mixed>
     */
    private function payload(ColorProfile $profile, User $stylist): array
    {
        $label = $profile->labelBy($stylist);

        return [
            'id' => $profile->id,
            'created_for_humans' => $profile->created_at?->diffForHumans(),
            'person_ref' => $profile->person_ref,
            'lighting' => $profile->lighting,
            // The photo while it lasts. Nothing here extends its life; once the
            // purge has run the row is still a complete piece of the dataset.
            'views' => $profile->images()->pluck('kind')->values()->all(),
            'label' => $label === null ? null : [
                'season' => $label->season,
                'season_alt' => $label->season_alt,
                'undertone' => $label->undertone,
                'depth' => $label->depth,
                'chroma' => $label->chroma,
                'stylist_confidence' => $label->stylist_confidence,
                'notes' => $label->notes,
            ],
            // What the engine said, for the page to keep hidden until a verdict
            // is in. See the view.
            'engine' => [
                'season' => $profile->season,
                'confidence' => $profile->confidence,
                'seasons' => $profile->rankedSeasons(),
                'low_confidence' => $profile->hasLowConfidence(),
            ],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function options(): array
    {
        return [
            'seasons' => ColorProfileLabel::SEASONS,
            'undertones' => ColorProfileLabel::UNDERTONES,
            'depths' => ColorProfileLabel::DEPTHS,
            'chromas' => ColorProfileLabel::CHROMAS,
            'confidences' => ColorProfileLabel::CONFIDENCES,
        ];
    }
}

<?php

namespace App\Http\Controllers\Membership;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberColorProfileRequest;
use App\Models\ColorProfile;
use App\Models\ColorProfileImage;
use App\Models\StyleProfile;
use App\Services\Color\ColorProfileIntake;
use App\Services\Color\MemberColorPresenter;
use App\Services\Membership\AccountShell;
use App\Services\Onboarding\StyleQuiz;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Кольоротип" in the member account: upload a face photo, get a season and
 * its palette.
 *
 * The member's counterpart of the internal instrument at /admin/color, sharing
 * its intake (ColorProfileIntake) and its engine, and NOT sharing its output:
 * a member gets MemberColorPresenter's shape — season, words, swatches, what
 * to do differently — never a measurement, a score or a debug picture. The
 * job is asked for no debug views at all, so none exist to leak.
 *
 * Every route answers 404 to anyone who may not have it: the two flags, the
 * session and the ownership check all say the same thing. The photo is served
 * only through {@see photo()}, behind that check, and only while it exists —
 * it is on the same purge clock as every other face on the site.
 */
class ColorController extends Controller
{
    public function __construct(
        private readonly AccountShell $shell,
        private readonly MemberColorPresenter $presenter,
        private readonly ColorProfileIntake $intake,
    ) {}

    /**
     * GET /account/color — the page, opened on the member's latest analysis,
     * with their pinned season beside it when the latest is not that one (a
     * retake that is still running, or came back without a season).
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        // Newest first, the id breaking a same-second tie: two uploads in one
        // second must open on the later one, not on whichever the DB returns.
        $latest = ColorProfile::query()
            ->where('user_id', $user->id)
            ->latest()
            ->orderByDesc('id')
            ->first();

        $pinned = $user->colorProfile;
        $hair = $user->styleProfile?->answers['natural_hair'] ?? null;

        return view('membership.color', $this->shell->shared($request, 'color') + [
            'profile' => $latest ? $this->presenter->present($latest) : null,
            'pinned' => $pinned && ! $pinned->is($latest) ? $this->presenter->present($pinned) : null,
            'naturalHair' => $hair === null ? '' : ($hair ? '1' : '0'),
            'previousHair' => (string) ($user->styleProfile?->answers['previous_hair'] ?? ''),
            // The quiz answers in words, for "Твої відповіді" on the sheet.
            'quizSummary' => StyleQuiz::summary($user->styleProfile?->answers),
            'photoHours' => (int) config('privacy.photo_hard_delete_hours'),
        ]);
    }

    /**
     * POST /account/color — a new analysis. The consent tick is recorded on
     * the row with its moment.
     *
     * The engine gets the member's colour answers from the style quiz, with
     * the hair question as answered on this page on top — and that answer is
     * kept in the quiz too, since dyed hair is a fact about the member, not
     * about one photo.
     */
    public function store(StoreMemberColorProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $style = $user->styleProfile;
        $answers = array_merge($style?->answers ?? [], $request->quiz() ?? []);

        if ($request->quiz() !== null) {
            $style ??= new StyleProfile(['user_id' => $user->id]);
            $style->answers = $answers;
            $style->save();
        }

        $profile = $this->intake->start(
            $user,
            $request->file('photo'),
            [
                'quiz' => StyleQuiz::colourQuiz($answers),
                'consented_at' => config('consent.required') ? now() : null,
            ],
            debug: false,
        );

        return response()->json($this->presenter->present($profile), 201);
    }

    /**
     * GET /account/color/{profile} — the current state, for polling.
     */
    public function show(Request $request, ColorProfile $profile): JsonResponse
    {
        $this->own($request, $profile);

        return response()->json($this->presenter->present($profile));
    }

    /**
     * GET /account/color/{profile}/photo — the uploaded photo, streamed back to
     * its owner while it still exists.
     */
    public function photo(Request $request, ColorProfile $profile): StreamedResponse
    {
        $this->own($request, $profile);

        $image = $profile->images()->where('kind', ColorProfileImage::KIND_SOURCE)->first();
        abort_if($image === null, 404);

        $disk = Storage::disk($image->disk);
        abort_unless($disk->exists($image->path), 404);

        return $disk->response($image->path, null, [
            // Somebody's face behind a session check: never cached where a
            // cache would serve it without one.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function own(Request $request, ColorProfile $profile): void
    {
        abort_unless($profile->user_id === $request->user()->id, 404);
    }
}

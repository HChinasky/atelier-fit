<?php

namespace App\Http\Controllers\Membership;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStyleQuizRequest;
use App\Models\StyleProfile;
use App\Services\Color\ColorProfileIntake;
use App\Services\Membership\AccountShell;
use App\Services\Onboarding\StyleQuiz;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The style quiz, offered once after sign-up and editable from the profile.
 *
 * Its last step, while the colour engine is on, is the face photo: sent with
 * the answers, it starts the colour analysis in the same request — the
 * engine reads the quiz's colour answers beside the photo — and the member
 * lands on the colour page to watch it finish. Without a photo the answers
 * are kept and the colour page asks for one there.
 *
 * Skipping the whole quiz is recorded, so the offer is not repeated.
 */
class OnboardingController extends Controller
{
    public function __construct(private readonly AccountShell $shell) {}

    /**
     * GET /account/welcome
     */
    public function show(Request $request): View
    {
        // Back from a failed submit (a photo without the consent tick, a file
        // that is not an image): the answers just given, not the stored ones,
        // and straight to the photo step where the error is.
        $answers = $request->session()->hasOldInput()
            ? StyleQuiz::normalise($request->old())
            : ($request->user()->styleProfile?->answers ?? []);

        return view('membership.onboarding', $this->shell->shared($request, 'onboarding') + [
            'questions' => StyleQuiz::questions(),
            'answers' => $answers,
            'withPhoto' => (bool) config('features.color_analysis'),
            'photoHours' => (int) config('privacy.photo_hard_delete_hours'),
            'photoError' => $request->session()->get('errors')?->first('photo') ?: $request->session()->get('errors')?->first('consent'),
            // ?step=metal opens on that question: the answers card on the
            // colour page links each answer straight back to it.
            'startKey' => (string) $request->query('step', ''),
            // Answered before: the selfie step says what a photo changes.
            'editing' => $request->user()->styleProfile?->completed_at !== null,
        ]);
    }

    /**
     * POST /account/welcome
     */
    public function store(StoreStyleQuizRequest $request, ColorProfileIntake $intake): RedirectResponse
    {
        $user = $request->user();
        $answers = $request->answers();

        StyleProfile::updateOrCreate(
            ['user_id' => $user->id],
            ['answers' => $answers, 'completed_at' => now()],
        );

        $plan = ['plan' => $request->query('plan')];

        if (config('features.color_analysis') && $request->hasFile('photo')) {
            $intake->start(
                $user,
                $request->file('photo'),
                ['quiz' => StyleQuiz::colourQuiz($answers), 'consented_at' => config('consent.required') ? now() : null],
                debug: false,
            );

            return redirect()->route('membership.account.color', $plan);
        }

        return redirect()->to(config('features.color_analysis')
            ? route('membership.account.color', $plan)
            : route('membership.account.dashboard', $plan));
    }

    /**
     * POST /account/welcome/skip
     */
    public function skip(Request $request): RedirectResponse
    {
        $profile = StyleProfile::firstOrNew(['user_id' => $request->user()->id]);
        // A member who once answered and then skips an edit keeps their answers.
        if ($profile->completed_at === null) {
            $profile->skipped_at = now();
        }
        $profile->save();

        return redirect()->route('membership.account.dashboard', ['plan' => $request->query('plan')]);
    }
}

<?php

use App\Jobs\AnalyzeColorProfile;
use App\Models\ColorProfile;
use App\Models\StyleProfile;
use App\Models\User;
use App\Services\Onboarding\StyleQuiz;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * The style quiz after sign-up: offered once, every answer optional, the whole
 * quiz skippable, the answers kept on the member and editable later. The
 * colour answers are the engine's own field names and values; the shopping
 * answers never reach it.
 */

beforeEach(function () {
    config(['features.membership' => true, 'features.color_analysis' => true]);
});

function quizMember(): User
{
    return User::factory()->emailOnly()->create();
}

// --- the gates -------------------------------------------------------------

it('is a plain 404 while membership is off', function () {
    config(['features.membership' => false]);

    $this->actingAs(quizMember())->get('/account/welcome')->assertNotFound();
});

it('sends a guest to sign in', function () {
    $this->get('/account/welcome')->assertRedirect(route('login'));
});

// --- the page --------------------------------------------------------------

it('asks every question, one card each, with the whole quiz skippable', function () {
    $page = $this->actingAs(quizMember())->get('/account/welcome')->assertOk()
        ->assertSee('Розкажи трохи про себе')
        ->assertSee('id="onboarding-skip"', escape: false)
        ->assertSee('Крок 1 з 8');

    foreach (StyleQuiz::questions() as $key => $question) {
        $page->assertSee('id="question-'.$key.'"', escape: false)
            ->assertSee($question['question']);
    }
});

it('lists the steps by their short names, numbered and grouped, and slides between them', function () {
    $page = $this->actingAs(quizMember())->get('/account/welcome')->assertOk()
        ->assertSee('class="quiz-steps"', escape: false)
        ->assertSeeInOrder(['class="step-group"', 'Про кольори', 'Про покупки', 'Селфі'], escape: false)
        ->assertSee('Кілька коротких запитань допоможуть персоналізувати рекомендації')
        ->assertSee('x-transition:enter-start="q-enter-from"', escape: false);

    foreach (StyleQuiz::questions() as $question) {
        $page->assertSee($question['short']);
    }
});

it('opens with the member\'s earlier answers in place', function () {
    $member = quizMember();
    StyleProfile::factory()->for($member)->create(['answers' => ['metal' => 'silver', 'size' => 'M']]);

    $html = $this->actingAs($member)->get('/account/welcome')->assertOk()->getContent();

    // @js hands Alpine the answers as JSON with its quotes "-escaped.
    expect($html)->toContain('\u0022metal\u0022:\u0022silver\u0022')
        ->and($html)->toContain('\u0022size\u0022:\u0022M\u0022');
});

// --- answering -------------------------------------------------------------

it('keeps the answers, typed, and moves on to the colour page', function () {
    $member = quizMember();

    $this->actingAs($member)->post('/account/welcome', [
        'natural_hair' => '0',
        'metal' => 'gold',
        'tanning' => 'tans',
        'clarity' => '',
        'categories' => ['dresses', 'lower_body'],
        'size' => 'S',
        'budget' => '200_500',
    ])->assertRedirect(route('membership.account.color'));

    $style = $member->styleProfile()->sole();
    // Compared as maps: Postgres jsonb keeps its own key order. The picked
    // categories come back in the question's order, not the click order.
    expect($style->answers)->toEqual([
        'natural_hair' => false,
        'metal' => 'gold',
        'tanning' => 'tans',
        'categories' => ['lower_body', 'dresses'],
        'size' => 'S',
        'budget' => '200_500',
    ])
        ->and($style->completed_at)->not->toBeNull()
        ->and($style->colourQuiz())->toEqual(['natural_hair' => false, 'metal' => 'gold', 'tanning' => 'tans']);
});

it('accepts a quiz with nothing answered at all', function () {
    $member = quizMember();

    $this->actingAs($member)->post('/account/welcome')->assertRedirect(route('membership.account.color'));

    expect($member->styleProfile->answers)->toBe([])
        ->and($member->styleProfile->colourQuiz())->toBeNull();
});

it('goes to the dashboard instead while the colour engine is off', function () {
    config(['features.color_analysis' => false]);

    $this->actingAs(quizMember())->post('/account/welcome', ['size' => 'L'])
        ->assertRedirect(route('membership.account.dashboard'));
});

it('refuses an answer that is not one of the question\'s options', function (string $field, mixed $value, string $error) {
    $member = quizMember();

    $this->actingAs($member)->post('/account/welcome', [$field => $value])->assertSessionHasErrors($error);

    expect($member->styleProfile)->toBeNull();
})->with([
    'metal' => ['metal', 'platinum', 'metal'],
    'hair' => ['natural_hair', 'maybe', 'natural_hair'],
    'size' => ['size', 'XXXL', 'size'],
    'categories' => ['categories', ['shoes'], 'categories.0'],
]);

it('replaces earlier answers on an edit', function () {
    $member = quizMember();
    StyleProfile::factory()->completed()->for($member)->create();

    $this->actingAs($member)->post('/account/welcome', ['size' => 'XL'])->assertRedirect();

    expect($member->styleProfile->fresh()->answers)->toBe(['size' => 'XL']);
});

// --- the selfie step -------------------------------------------------------

it('ends with a selfie step, with the consent and the honest deletion window', function () {
    config(['privacy.photo_hard_delete_hours' => 60]);

    $this->actingAs(quizMember())->get('/account/welcome')->assertOk()
        ->assertSee('id="question-photo"', escape: false)
        ->assertSee('Останній крок — селфі')
        ->assertSee('enctype="multipart/form-data"', escape: false)
        ->assertSee('name="photo"', escape: false)
        ->assertSee('id="selfie-consent"', escape: false)
        ->assertSee('не скриншот і не фото, переслане в месенджері')
        ->assertSee('видаляється протягом 60 годин')
        ->assertSee('legal/privacy-policy');
});

it('has no selfie step while the colour engine is off', function () {
    config(['features.color_analysis' => false]);

    $this->actingAs(quizMember())->get('/account/welcome')->assertOk()
        ->assertDontSee('id="question-photo"', escape: false)
        ->assertSee('Крок 1 з 7');
});

it('keeps the answers and starts the colour analysis from the selfie and the quiz together', function () {
    Storage::fake('r2');
    Queue::fake();
    $member = quizMember();

    $this->actingAs($member)->post('/account/welcome', [
        'natural_hair' => '1',
        'metal' => 'silver',
        'size' => 'M',
        'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 1200),
        'consent' => '1',
    ])->assertRedirect(route('membership.account.color'));

    $profile = ColorProfile::sole();
    expect($member->styleProfile->completed_at)->not->toBeNull()
        ->and($profile->user_id)->toBe($member->id)
        ->and($profile->consented_at)->not->toBeNull()
        ->and($profile->quiz)->toEqual(['natural_hair' => true, 'metal' => 'silver'])
        ->and($profile->status)->toBe(ColorProfile::STATUS_PENDING);

    Queue::assertPushed(AnalyzeColorProfile::class, fn (AnalyzeColorProfile $job) => $job->profile->is($profile)
        && $job->debug === false
        && $job->queue === config('color_analysis.queue'));
});

it('takes no photo without the consent tick, and reopens on the selfie step with the answers kept', function () {
    Storage::fake('r2');
    Queue::fake();
    $member = quizMember();

    $this->actingAs($member)->from('/account/welcome')->post('/account/welcome', [
        'metal' => 'gold',
        'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 1200),
    ])->assertRedirect('/account/welcome')->assertSessionHasErrors('consent');

    expect(ColorProfile::count())->toBe(0)
        ->and($member->styleProfile()->exists())->toBeFalse();
    Queue::assertNothingPushed();

    // The page the redirect lands on: straight to the last step (index 7),
    // the error said, the answer just given still chosen.
    $page = $this->actingAs($member->fresh())->from('/account/welcome')->followingRedirects()->post('/account/welcome', [
        'metal' => 'gold',
        'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 1200),
    ])->assertOk()
        ->assertSee('id="selfie-error"', escape: false)
        ->assertSee('потрібна ваша згода на обробку фото')
        ->assertSee('), 7)"', escape: false);

    // @js escapes the quotes of the answers object; match around them.
    expect($page->getContent())->toMatch('/metal\W+gold/');
});

it('refuses a file that is not an image, in the member\'s words', function () {
    $this->actingAs(quizMember())->post('/account/welcome', [
        'photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        'consent' => '1',
    ])->assertSessionHasErrors(['photo' => 'Підійде фото у форматі JPEG, PNG, WebP або HEIC.']);
});

it('ignores a photo while the colour engine is off', function () {
    config(['features.color_analysis' => false]);
    Storage::fake('r2');
    Queue::fake();

    $this->actingAs(quizMember())->post('/account/welcome', [
        'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 1200),
        'consent' => '1',
    ])->assertRedirect(route('membership.account.dashboard'));

    expect(ColorProfile::count())->toBe(0);
    Queue::assertNothingPushed();
});

// --- skipping --------------------------------------------------------------

it('records a skip, so the quiz is not offered again', function () {
    $member = quizMember();

    $this->actingAs($member)->post('/account/welcome/skip')->assertRedirect(route('membership.account.dashboard'));

    expect($member->styleProfile->skipped_at)->not->toBeNull()
        ->and($member->styleProfile->wasOffered())->toBeTrue();
});

it('keeps a completed quiz completed when an edit is skipped', function () {
    $member = quizMember();
    $style = StyleProfile::factory()->completed()->for($member)->create();

    $this->actingAs($member)->post('/account/welcome/skip')->assertRedirect();

    expect($style->fresh()->skipped_at)->toBeNull()
        ->and($style->fresh()->answers)->toEqual($style->answers);
});

// --- the offer after sign-in -----------------------------------------------

it('offers the quiz only while it has been neither answered nor skipped', function () {
    expect(StyleProfile::factory()->make()->wasOffered())->toBeFalse()
        ->and(StyleProfile::factory()->completed()->make()->wasOffered())->toBeTrue()
        ->and(StyleProfile::factory()->skipped()->make()->wasOffered())->toBeTrue();
});

it('sends a signed-in member from the dashboard to the quiz until it has been offered', function () {
    /* A remembered session never passes the sign-in again, so the offer
     * cannot live only there: members already signed in when the quiz
     * shipped would never see it. */
    $member = quizMember();

    $this->actingAs($member)->get('/account?plan=couture')
        ->assertRedirect(route('membership.account.onboarding', ['plan' => 'couture']));

    $this->actingAs($member)->post('/account/welcome/skip')->assertRedirect();
    // A fresh instance, as every real request loads one.
    $this->actingAs($member->fresh())->get('/account')->assertOk();
});

it('does not divert a member who arrives with a piece picked', function () {
    $this->actingAs(quizMember())->get('/account?pick=1')->assertOk();
});

it('lets the other account pages open without the quiz', function () {
    $this->actingAs(quizMember())->get('/account/settings')->assertOk();
});

it('links the quiz from the profile page', function () {
    $this->actingAs(quizMember())->get('/account/settings')->assertOk()
        ->assertSee('id="style-quiz-card"', escape: false)
        ->assertSee(route('membership.account.onboarding', ['plan' => 'shopaholic']), escape: false)
        ->assertSee('Пройти анкету');
});

// --- editing the answers ---------------------------------------------------

it('opens on the question named in ?step=, and on the first for anything else', function () {
    $member = quizMember();

    $this->actingAs($member)->get('/account/welcome?step=metal')->assertOk()
        ->assertSee('Крок 2 з 8')
        ->assertSee(', 1)"', escape: false);
    $this->actingAs($member)->get('/account/welcome?step=nonsense')->assertOk()
        ->assertSee('Крок 1 з 8');
});

it('offers "Зберегти зміни" and says what a new selfie changes, once the quiz was answered', function () {
    $member = quizMember();
    StyleProfile::factory()->completed()->for($member)->create();

    $this->actingAs($member)->get('/account/welcome')->assertOk()
        ->assertSee('id="onboarding-save"', escape: false)
        ->assertSee('id="selfie-editing-hint"', escape: false);
});

it('shows neither to a first-time member', function () {
    $this->actingAs(quizMember())->get('/account/welcome')->assertOk()
        ->assertDontSee('id="onboarding-save"', escape: false)
        ->assertDontSee('id="selfie-editing-hint"', escape: false);
});

it('lists the answers on the colour sheet, each linking back to its question', function () {
    config(['features.color_analysis' => true]);
    $member = quizMember();
    StyleProfile::factory()->for($member)->create(['answers' => ['metal' => 'silver', 'categories' => ['dresses', 'upper_body']]]);
    ColorProfile::factory()->classified()->create(['user_id' => $member->id]);

    $this->actingAs($member)->get('/account/color')->assertOk()
        ->assertSee('id="color-answers"', escape: false)
        ->assertSee('Твої відповіді')
        ->assertSee(route('membership.account.onboarding', ['step' => 'metal']), escape: false)
        ->assertSee('Срібло')
        ->assertSee('Не вказано')
        ->assertSee('Змінити відповіді');
});

it('words the answers for the summary: labels, several joined, unanswered as null', function () {
    $rows = collect(StyleQuiz::summary([
        'natural_hair' => false,
        'categories' => ['upper_body', 'dresses'],
        'budget' => '200_500',
    ]))->keyBy('key');

    expect($rows['natural_hair'])->toBe(['key' => 'natural_hair', 'short' => 'Волосся', 'answer' => 'Ні, фарбоване', 'colour' => true])
        ->and($rows['categories']['answer'])->toBe('Топи, блузи, светри, Сукні')
        ->and($rows['budget']['answer'])->toBe('200–500 AED')
        ->and($rows['metal']['answer'])->toBeNull()
        ->and($rows->keys()->all())->toBe(array_keys(StyleQuiz::questions()));
});

// --- the quiz, as data -----------------------------------------------------

it('spells the colour answers exactly as the engine\'s Quiz model does', function () {
    $questions = StyleQuiz::questions();

    expect(StyleQuiz::COLOUR_KEYS)->toBe(['natural_hair', 'metal', 'tanning', 'clarity'])
        ->and(array_keys($questions['metal']['options']))->toBe(['gold', 'silver', 'both'])
        ->and(array_keys($questions['tanning']['options']))->toBe(['burns', 'tans', 'both'])
        ->and(array_keys($questions['clarity']['options']))->toBe(['clear', 'soft', 'unsure'])
        ->and($questions)->not->toHaveKey('veins');

    foreach (StyleQuiz::COLOUR_KEYS as $key) {
        expect($questions[$key]['group'])->toBe('colour');
    }
});

it('keeps the shopping answers away from the engine', function () {
    expect(StyleQuiz::colourQuiz(['size' => 'M', 'budget' => 'under_200', 'categories' => ['dresses']]))->toBeNull()
        ->and(StyleQuiz::colourQuiz(['size' => 'M', 'clarity' => 'clear']))->toBe(['clarity' => 'clear'])
        ->and(StyleQuiz::colourQuiz(['veins' => 'blue']))->toBeNull();
});

it('opens the photo picker from a real file input and re-syncs when the browser restores the page', function () {
    $this->actingAs(quizMember())->get('/account/welcome')
        ->assertOk()
        ->assertSee('id="selfie-input"', escape: false)
        ->assertDontSee('hidden x-ref="photo"', escape: false)
        ->assertSee('x-on:pageshow.window="restore()"', escape: false);
});

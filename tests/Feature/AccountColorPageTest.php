<?php

use App\Jobs\AnalyzeColorProfile;
use App\Models\ColorProfile;
use App\Models\ColorProfileImage;
use App\Models\StyleProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * "Кольоротип" in the member account: a face photo in, a season and a
 * palette out — and nothing of the instrument behind it. Two flags gate it
 * (membership for the area, color_analysis for the engine), the session and
 * the ownership check answer 404 like every other refusal, consent is
 * required and recorded, and the JSON a member polls carries no measurement.
 */

beforeEach(function () {
    Storage::fake('r2');
    config([
        'features.membership' => true,
        'features.color_analysis' => true,
        'privacy.photo_hard_delete_hours' => 60,
    ]);
});

function colorMember(): User
{
    return User::factory()->emailOnly()->create();
}

/** A member's profile with its photo present in (fake) storage. */
function memberProfile(User $user, array $state = [], string $extension = 'jpg'): ColorProfile
{
    // raw() of a factory state has already made a user of its own; the row
    // must belong to THIS member, or every ownership check answers 404.
    $profile = ColorProfile::factory()->create(['user_id' => $user->id] + Arr::except($state, 'user_id'));
    $image = ColorProfileImage::factory()->for($profile, 'profile')->create([
        'path' => 'uploads/color/2026-09-23/'.fake()->uuid().'/source.'.$extension,
    ]);
    Storage::disk($image->disk)->put($image->path, 'PHOTO-BYTES');

    return $profile;
}

function selfie(): UploadedFile
{
    return UploadedFile::fake()->image('selfie.jpg', 900, 1200);
}

// --- the gates -------------------------------------------------------------

it('is a plain 404 while the colour engine flag is off, nav item included', function () {
    config(['features.color_analysis' => false]);

    $this->actingAs(colorMember())->get('/account/color')->assertNotFound();
    $this->actingAs(User::factory()->emailOnly()->onboarded()->create())->get('/account')->assertOk()->assertDontSee('>Мої кольори<', escape: false);
});

it('is a plain 404 while the membership flag is off', function () {
    config(['features.membership' => false]);

    $this->actingAs(colorMember())->get('/account/color')->assertNotFound();
});

it('sends a guest to sign in', function () {
    $this->get('/account/color')->assertRedirect(route('login'));
});

it('lists the page in the account navigation once the engine is on', function () {
    $this->actingAs(User::factory()->emailOnly()->onboarded()->create())->get('/account')
        ->assertOk()
        ->assertSee('>Мої кольори<', escape: false)
        ->assertSee(route('membership.account.color', ['plan' => 'shopaholic']), escape: false);
});

it('hides another member\'s analysis and photo, as 404 rather than 403', function () {
    $owner = colorMember();
    $profile = memberProfile($owner, ColorProfile::factory()->classified()->raw());

    $this->actingAs(colorMember())->getJson('/account/color/'.$profile->id)->assertNotFound();
    $this->actingAs(colorMember())->get('/account/color/'.$profile->id.'/photo')->assertNotFound();
    $this->actingAs($owner)->getJson('/account/color/'.$profile->id)->assertOk();
});

// --- the page --------------------------------------------------------------

it('renders the photo panel, the rules, the one question, the consent and the result panel', function () {
    $this->actingAs(colorMember())->get('/account/color')
        ->assertOk()
        ->assertSee('Твої кольори')
        ->assertSee('id="color-photo-panel"', escape: false)
        ->assertSee('id="color-rules"', escape: false)
        ->assertSee('Денне світло, обличчям до вікна')
        ->assertSee('не скриншот і не фото, переслане в месенджері')
        ->assertSee('id="color-hair"', escape: false)
        ->assertSee('Колір волосся зараз натуральний?')
        ->assertSee('id="color-consent"', escape: false)
        ->assertSee('видаляється протягом 60 годин')
        ->assertSee('legal/privacy-policy')
        ->assertSee('id="color-result-panel"', escape: false)
        ->assertSee("Тут з'явиться твій сезон", escape: false);
});

it('shows nothing of the instrument: no scores, axes, zones or white balance in the markup', function () {
    seedPalettes();
    $member = colorMember();
    memberProfile($member, ColorProfile::factory()->classified()->raw());

    $page = $this->actingAs($member)->get('/account/color')->assertOk();

    foreach (['"score"', '"confidence"', '"axes"', '"zones"', '"white_balance"', '"samples"', 'L*', 'Чернетка', 'draft'] as $forbidden) {
        $page->assertDontSee($forbidden, escape: false);
    }
});

it('opens on the member\'s latest analysis with its season and palette', function () {
    seedPalettes();
    $member = colorMember();
    memberProfile($member, ColorProfile::factory()->classified('true_winter', 'bright_winter')->raw(['created_at' => now()->subDay()]));
    memberProfile($member, ColorProfile::factory()->classified('deep_autumn', 'true_autumn')->raw());

    // The season is rendered on the server; the palette travels as JSON for
    // Alpine (its swatches are asserted through the JSON endpoint below).
    $this->actingAs($member)->get('/account/color')
        ->assertOk()
        ->assertSee('Глибока осінь')
        ->assertDontSee('Справжня зима')
        ->assertSee('Твій найбільш ймовірний кольоротип')
        ->assertSee('AI-аналіз визначив найбільш ймовірний сезон за фото')
        ->assertSee('id="color-palette"', escape: false)
        ->assertSee('Що носити, а що залишити');
});

it('opens on the sheet once there is a season: portrait, traits, the four groups, harmony, tips, signature', function () {
    seedPalettes();
    $member = colorMember();
    memberProfile($member, ColorProfile::factory()->classified('soft_summer', 'soft_autumn')->raw());

    $page = $this->actingAs($member)->get('/account/color')
        ->assertOk()
        ->assertSee('id="color-sheet"', escape: false)
        ->assertSee('id="color-portrait"', escape: false)
        ->assertSee('Твоя природна краса')
        ->assertSee('id="color-traits"', escape: false)
        ->assertSee('id="color-harmony"', escape: false)
        ->assertSee(__('seasons.descriptions.soft_summer', [], 'uk'), escape: false)
        ->assertSee('id="color-tips"', escape: false)
        ->assertSee('id="color-signature"', escape: false)
        ->assertSee('id="color-again"', escape: false);

    // The intake is still on the page, for "Визначити ще раз", but hidden
    // before Alpine wakes so the sheet does not open on a flash of form.
    expect($page->getContent())->toMatch('/<div id="color-intake"[^>]*x-cloak/');
});

it('opens on the intake while there is no season yet', function () {
    $html = $this->actingAs(colorMember())->get('/account/color')->assertOk()->getContent();

    expect($html)->not->toMatch('/<div id="color-intake"[^>]*x-cloak/')
        ->and($html)->toMatch('/<div class="sheet" id="color-sheet"[^>]*x-cloak/');
});

it('leaves the instrument\'s readings off the sheet: no axes, confidence or measured colours', function () {
    seedPalettes();
    $member = colorMember();
    memberProfile($member, ColorProfile::factory()->classified('deep_winter', 'deep_autumn', 0.2)->raw());

    $page = $this->actingAs($member)->get('/account/color')->assertOk();

    foreach (['Температура', 'Глибина', 'Чистота', 'Низька впевненість', '-0.41', '0.68', '0.2'] as $forbidden) {
        $page->assertDontSee($forbidden, escape: false);
    }
});

// --- the upload ------------------------------------------------------------

it('needs the consent tick before it takes a photo', function () {
    Queue::fake();

    $this->actingAs(colorMember())
        ->postJson('/account/color', ['photo' => selfie()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['consent']);

    Queue::assertNothingPushed();
    expect(ColorProfile::count())->toBe(0);
});

it('stores the photo privately, records the consent and the answer, and queues the analysis without debug views', function () {
    Queue::fake();
    $member = colorMember();

    $response = $this->actingAs($member)
        ->postJson('/account/color', ['photo' => selfie(), 'consent' => '1', 'natural_hair' => '0'])
        ->assertCreated()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('pending', true);

    $profile = ColorProfile::sole();
    expect($profile->user_id)->toBe($member->id)
        ->and($profile->consented_at)->not->toBeNull()
        ->and($profile->quiz)->toBe(['natural_hair' => false])
        ->and($response->json('id'))->toBe($profile->id);

    $image = $profile->images()->where('kind', ColorProfileImage::KIND_SOURCE)->sole();
    expect($image->path)->toStartWith('uploads/color/')
        ->and($image->expires_at->diffInHours(now()->addHours(60), true))->toBeLessThan(1);
    Storage::disk('r2')->assertExists($image->path);

    Queue::assertPushed(AnalyzeColorProfile::class, fn (AnalyzeColorProfile $job) => $job->profile->is($profile)
        && $job->debug === false
        && $job->queue === config('color_analysis.queue'));
});

it('leaves the hair answer out when the member skipped it', function () {
    Queue::fake();

    $this->actingAs(colorMember())
        ->postJson('/account/color', ['photo' => selfie(), 'consent' => '1'])
        ->assertCreated();

    expect(ColorProfile::sole()->quiz)->toBeNull();
});

it('sends the engine the colour answers from the style quiz, and never the shopping ones', function () {
    Queue::fake();
    $member = colorMember();
    StyleProfile::factory()->for($member)->create(['answers' => [
        'natural_hair' => true, 'metal' => 'silver', 'clarity' => 'soft', 'veins' => 'blue', 'size' => 'M', 'budget' => 'under_200',
    ]]);

    $this->actingAs($member)->postJson('/account/color', ['photo' => selfie(), 'consent' => '1'])->assertCreated();

    // Compared as a map: jsonb keeps its own key order.
    // veins is an old answer the quiz no longer asks: stored, never sent.
    expect(ColorProfile::sole()->quiz)->toEqual(['natural_hair' => true, 'metal' => 'silver', 'clarity' => 'soft']);
});

it('lets the hair answer on the page win, and keeps it in the style quiz', function () {
    Queue::fake();
    $member = colorMember();
    $style = StyleProfile::factory()->completed()->for($member)->create();

    $this->actingAs($member)
        ->postJson('/account/color', ['photo' => selfie(), 'consent' => '1', 'natural_hair' => '0'])
        ->assertCreated();

    expect(ColorProfile::sole()->quiz['natural_hair'])->toBeFalse()
        ->and($style->fresh()->answers['natural_hair'])->toBeFalse()
        ->and($style->fresh()->answers['size'])->toBe($style->answers['size'])
        ->and($style->fresh()->completed_at)->not->toBeNull();
});

it('starts a style quiz row from the hair answer alone, without marking the quiz seen', function () {
    Queue::fake();
    $member = colorMember();

    $this->actingAs($member)
        ->postJson('/account/color', ['photo' => selfie(), 'consent' => '1', 'natural_hair' => '1'])
        ->assertCreated();

    $style = $member->styleProfile()->sole();
    expect($style->answers)->toBe(['natural_hair' => true])
        ->and($style->wasOffered())->toBeFalse();
});

it('prefills the hair question from the style quiz', function () {
    $member = colorMember();
    StyleProfile::factory()->for($member)->create(['answers' => ['natural_hair' => false]]);

    $this->actingAs($member)->get('/account/color')->assertOk()
        ->assertSee("colorType(null, null, '0'", escape: false);
});

it('carries the pinned season beside a later attempt that placed none', function () {
    seedPalettes();
    $member = colorMember();
    $pinned = memberProfile($member, ColorProfile::factory()->classified('true_winter', 'bright_winter')->raw(['created_at' => now()->subHour()]));
    $member->forceFill(['color_profile_id' => $pinned->id])->save();
    memberProfile($member, ColorProfile::factory()->rejected('low_light')->raw());

    $this->actingAs($member)->get('/account/color')->assertOk()
        ->assertSee('Повернутися до мого сезону')
        // The pinned profile travels as JSON for Alpine; its slug is the ASCII part of it.
        ->assertSee('true_winter', escape: false);
});

it('does not repeat the pinned season when it is the latest', function () {
    seedPalettes();
    $member = colorMember();
    $pinned = memberProfile($member, ColorProfile::factory()->classified()->raw());
    $member->forceFill(['color_profile_id' => $pinned->id])->save();

    expect($this->actingAs($member)->get('/account/color')->assertOk()->getContent())
        ->toMatch('/colorType\(JSON\.parse\(.*?\), null, /s');
});

it('refuses a file that is not an image, in the member\'s words', function () {
    Queue::fake();

    $this->actingAs(colorMember())
        ->postJson('/account/color', ['photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'), 'consent' => '1'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.photo.0', 'Підійде фото у форматі JPEG, PNG, WebP або HEIC.');
});

// --- what the member is told ------------------------------------------------

it('tells the member the season in words, and the palette as swatches, never the numbers', function () {
    seedPalettes();
    $member = colorMember();
    $profile = memberProfile($member, ColorProfile::factory()->classified('deep_winter', 'deep_autumn', 0.62)->raw());

    $json = $this->actingAs($member)->getJson('/account/color/'.$profile->id)->assertOk()->json();

    expect($json['status'])->toBe('ok')
        ->and($json['season']['name'])->toBe('Глибока зима')
        ->and($json['season']['description'])->toContain('Темне волосся')
        ->and($json['alternative']['name'])->toBe('Глибока осінь')
        ->and($json['confidence_level'])->toBe('medium')
        ->and(array_column($json['palette'], 'name'))->toBe(['neutrals', 'base', 'accents', 'avoid'])
        ->and($json['palette'][3]['avoid'])->toBeTrue()
        ->and($json['palette'][0]['colors'][0]['hex'])->toMatch('/^#[0-9A-F]{6}$/i')
        ->and(array_keys($json['palette'][0]['colors'][0]))->toBe(['hex', 'ink'])
        ->and($json)->not->toHaveKeys(['axes', 'zones', 'white_balance', 'samples', 'confidence', 'classification', 'algo_version']);
});

it('always names the nearest alternative, and words how sure the reading is', function (float $confidence, string $level) {
    seedPalettes();
    $member = colorMember();
    $profile = memberProfile($member, ColorProfile::factory()->classified('deep_winter', 'deep_autumn', $confidence)->raw());

    $json = $this->actingAs($member)->getJson('/account/color/'.$profile->id)->json();

    expect($json['alternative']['name'])->toBe('Глибока осінь')
        ->and($json['confidence_level'])->toBe($level)
        ->and($json)->not->toHaveKey('confidence');
})->with([
    'the engine flagged it low' => [0.2, 'low'],
    'between the thresholds' => [0.5, 'medium'],
    'well ahead' => [0.8, 'high'],
]);

it('draws the confidence, the retake hint and the alternative on the sheet', function () {
    seedPalettes();
    $member = colorMember();
    memberProfile($member, ColorProfile::factory()->classified('bright_winter', 'bright_spring', 0.2)->raw());

    $this->actingAs($member)->get('/account/color')->assertOk()
        ->assertSee('id="color-confidence"', escape: false)
        ->assertSee('Впевненість аналізу')
        ->assertSee('id="color-retake-hint"', escape: false)
        ->assertSee('id="color-alternative"', escape: false)
        ->assertSee('Найближча альтернатива');
});

it('gives no alternative or confidence to a profile without a season', function () {
    $member = colorMember();
    $profile = memberProfile($member, ColorProfile::factory()->rejected('low_light')->raw());

    $json = $this->actingAs($member)->getJson('/account/color/'.$profile->id)->json();

    expect($json['alternative'])->toBeNull()
        ->and($json['confidence_level'])->toBeNull();
});

it('turns a refused photo into what to do differently, in Ukrainian', function () {
    $member = colorMember();
    $profile = memberProfile($member, ColorProfile::factory()->rejected('low_light')->raw());

    $this->actingAs($member)->getJson('/account/color/'.$profile->id)
        ->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('reject_message', __('color_analysis.low_light', [], 'uk'))
        ->assertJsonPath('season', null);
});

it('keeps a failure on our side apart from the photo, and says nothing operator-only', function () {
    $member = colorMember();
    $profile = memberProfile($member, ColorProfile::factory()->failed('Colour analysis service is unreachable.')->raw());

    $json = $this->actingAs($member)->getJson('/account/color/'.$profile->id)->assertOk()->json();

    expect($json['status'])->toBe('failed')
        ->and($json)->not->toHaveKey('error')
        ->and(json_encode($json))->not->toContain('unreachable');
});

it('reports a measured photo with no season as its own outcome', function () {
    $member = colorMember();
    $profile = memberProfile($member, ColorProfile::factory()->measured()->raw());

    $this->actingAs($member)->getJson('/account/color/'.$profile->id)
        ->assertOk()
        ->assertJsonPath('status', 'unplaced')
        ->assertJsonPath('season', null);
});

// --- the photo -------------------------------------------------------------

it('streams the member\'s own photo back while it exists, uncached', function () {
    $member = colorMember();
    $profile = memberProfile($member);

    $this->actingAs($member)->get('/account/color/'.$profile->id.'/photo')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
});

it('says the photo is gone once the purge has taken it', function () {
    $member = colorMember();
    $profile = memberProfile($member);
    $image = $profile->images()->sole();
    Storage::disk($image->disk)->delete($image->path);

    $this->actingAs($member)->get('/account/color/'.$profile->id.'/photo')->assertNotFound();
});

it('offers the photo back only in a format a browser can draw', function () {
    $member = colorMember();
    $jpeg = memberProfile($member, extension: 'jpg');
    $heic = memberProfile($member, extension: 'heic');

    expect($this->actingAs($member)->getJson('/account/color/'.$jpeg->id)->json('photo.available'))->toBeTrue()
        ->and($this->actingAs($member)->getJson('/account/color/'.$heic->id)->json('photo.available'))->toBeFalse();
});

it('sends the natural colour from before the dye, only with dyed hair', function () {
    Queue::fake();

    $this->actingAs(colorMember())
        ->postJson('/account/color', ['photo' => selfie(), 'consent' => '1', 'natural_hair' => '0', 'previous_hair' => 'dark'])
        ->assertCreated();

    expect(ColorProfile::sole()->quiz)->toBe(['natural_hair' => false, 'previous_hair' => 'dark']);
});

it('drops the previous hair colour when the hair is natural', function () {
    Queue::fake();

    $this->actingAs(colorMember())
        ->postJson('/account/color', ['photo' => selfie(), 'consent' => '1', 'natural_hair' => '1', 'previous_hair' => 'dark'])
        ->assertCreated();

    expect(ColorProfile::sole()->quiz)->toBe(['natural_hair' => true]);
});

it('sends dyed hair without a previous colour when none was picked', function () {
    Queue::fake();

    $this->actingAs(colorMember())
        ->postJson('/account/color', ['photo' => selfie(), 'consent' => '1', 'natural_hair' => '0'])
        ->assertCreated();

    expect(ColorProfile::sole()->quiz)->toBe(['natural_hair' => false]);
});

it('refuses a previous hair colour the engine does not know', function () {
    Queue::fake();

    $this->actingAs(colorMember())
        ->postJson('/account/color', ['photo' => selfie(), 'consent' => '1', 'natural_hair' => '0', 'previous_hair' => 'purple'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['previous_hair']);
});

it('asks about the colour before the dye on the colour page', function () {
    $html = $this->actingAs(colorMember())->get('/account/color')->assertOk()->getContent();

    expect($html)->toContain('id="color-previous-hair"')
        ->and($html)->toContain("body.append('previous_hair'");
});

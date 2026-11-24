<?php

use App\Jobs\AnalyzeColorProfile;
use App\Models\ColorProfile;
use App\Models\ColorProfileImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * The internal colour-analysis page.
 *
 * Two gates, and they answer different questions. The feature flag decides
 * whether these routes exist at all — a 404 for everyone, admins included — and
 * the role decides who gets past them once they do. The images are the part
 * worth being careful about: they are somebody's face, and the only way to them
 * is through a controller that checks both.
 */

beforeEach(function () {
    Storage::fake('r2');
    config([
        'features.color_analysis' => true,
        'privacy.photo_hard_delete_hours' => 60,
    ]);
});

function admin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

/** A profile owned by someone, with its photo present in storage. */
function ownedProfile(User $user, array $state = []): ColorProfile
{
    $profile = ColorProfile::factory()->for($user)->create($state);
    $image = ColorProfileImage::factory()->for($profile, 'profile')->create();
    Storage::disk($image->disk)->put($image->path, 'PHOTO-BYTES');

    return $profile;
}

// --- the flag ------------------------------------------------------------

it('does not exist at all while the flag is off', function () {
    config(['features.color_analysis' => false]);

    $this->actingAs(admin())->get('/admin/color')->assertNotFound();
});

it('is a plain 404 for a guest while the flag is off, never a login redirect', function () {
    /* A redirect would confirm the routes are there. The flag is judged before
     * auth on purpose — see the priority list in bootstrap/app.php. */
    config(['features.color_analysis' => false]);

    $this->get('/admin/color')->assertNotFound();
});

it('sends a guest to sign in while the flag is on', function () {
    config(['features.membership' => true]);

    $this->get('/admin/color')->assertRedirect(route('login'));
});

// --- the role ------------------------------------------------------------

it('tells a signed-in member the page is not there, rather than that it is forbidden', function () {
    /* 403 is the more literal answer and the wrong one: it would confirm
     * /admin/color exists and that there is an administrator tier. The flag
     * already answers 404 for the same reason — one story, not two. */
    $this->actingAs(User::factory()->create())->get('/admin/color')->assertNotFound();
});

it('hides every route of the area from a member, not just the page', function () {
    /* Probing which paths answer 404 and which answer something else would map
     * the whole area out. They all answer the same thing. */
    $admin = admin();
    $profile = ownedProfile($admin);
    $member = User::factory()->create();

    $this->actingAs($member)->get('/admin/color')->assertNotFound();
    $this->actingAs($member)->getJson('/admin/color/'.$profile->id)->assertNotFound();
    $this->actingAs($member)
        ->get('/admin/color/'.$profile->id.'/image/source')
        ->assertNotFound();
    $this->actingAs($member)->postJson('/admin/color', [
        'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 900),
    ])->assertNotFound();
});

it('lets an administrator in', function () {
    $this->actingAs(admin())->get('/admin/color')
        ->assertOk()
        ->assertSee('Colour analysis');
});

it('says on the page that none of this has been checked by a stylist', function () {
    /* The one thing a reader must not miss. The classifier's reference points
     * were reasoned rather than measured, and the palettes were generated
     * rather than chosen. */
    $this->actingAs(admin())->get('/admin/color')
        ->assertSee(__('seasons.draft_banner'), false)
        ->assertSee('not fitted to, or tested against', false);
});

it('adds the white-balance sentence only while the target is uncalibrated', function () {
    /* Two separate facts. Rolling them together would mean calibrating white
     * balance silently dropped the stylist caveat as well. */
    $this->actingAs(admin())->get('/admin/color')
        ->assertSee(__('seasons.draft_banner_uncalibrated'), false);

    expect(__('seasons.draft_banner_uncalibrated'))
        ->toContain(__('seasons.draft_banner'))
        ->not->toBe(__('seasons.draft_banner'));
});

// --- the season, the axes and the palette --------------------------------

it('shows the season, its score and the runner-up', function () {
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->classified()->raw(['user_id' => $user->id]));

    $this->actingAs($user)->getJson("/admin/color/{$profile->id}")
        ->assertOk()
        ->assertJsonPath('classification.seasons.0.slug', 'deep_winter')
        ->assertJsonPath('classification.seasons.0.name', 'Deep winter')
        ->assertJsonPath('classification.seasons.1.slug', 'deep_autumn')
        ->assertJsonPath('classification.draft', true)
        ->assertJsonPath('classification.version', 'draft-1');
});

it('places each axis marker itself rather than leaving it to the browser', function () {
    /* No arithmetic on a measurement in JavaScript: -1 is the left end, +1 the
     * right, and the page is handed the percentage. */
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->classified()->raw(['user_id' => $user->id]));

    $response = $this->actingAs($user)->getJson("/admin/color/{$profile->id}")->assertOk();

    $axes = collect($response->json('axes'))->keyBy('key');
    expect($axes['temperature']['value'])->toBe(-0.41)
        ->and($axes['temperature']['position'])->toBe(29.5)
        ->and($axes['temperature']['low'])->toBe('cool')
        ->and($axes['temperature']['high'])->toBe('warm')
        ->and((float) $axes['depth']['position'])->toBe(84.0);
});

it('says an axis was not measured instead of putting the marker in the middle', function () {
    /* Zero is a claim — "perfectly neutral". Null is the truth. */
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->classified()->raw([
        'user_id' => $user->id,
        'axes' => ['temperature' => null, 'depth' => 0.68, 'chroma' => 0.12],
    ]));

    $response = $this->actingAs($user)->getJson("/admin/color/{$profile->id}")->assertOk();

    $axes = collect($response->json('axes'))->keyBy('key');
    expect($axes['temperature']['value'])->toBeNull()
        ->and($axes['temperature']['position'])->toBeNull();
});

it('shows contrast as a number rather than as a fourth axis', function () {
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->classified()->raw(['user_id' => $user->id]));

    $this->actingAs($user)->getJson("/admin/color/{$profile->id}")
        ->assertOk()
        ->assertJsonPath('contrast.value', 0.63)
        ->assertJsonPath('contrast.hair_skin', 45.5)
        ->assertJsonCount(3, 'axes');
});

it('lists what the classifier could not use, and what each factor cost', function () {
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->classified()->raw([
        'user_id' => $user->id,
        'classifier' => [
            'version' => 'draft-1',
            'draft' => true,
            'low_confidence' => false,
            'exclusions' => [
                ['axis' => 'depth', 'feature' => 'hair_L', 'reason' => 'dyed_hair'],
            ],
            'confidence' => [
                'from_margin' => 0.7,
                'features_factor' => 0.75,
                'quality_factor' => 0.9,
                'final' => 0.4725,
            ],
        ],
    ]));

    $response = $this->actingAs($user)->getJson("/admin/color/{$profile->id}")->assertOk();

    expect($response->json('classification.exclusions.0.reason_text'))
        ->toBe(__('seasons.exclusions.dyed_hair'));
    expect(collect($response->json('classification.confidence_parts'))->pluck('value')->all())
        ->toBe([0.7, 0.75, 0.9, 0.4725]);
});

it('falls back to the raw slug rather than to silence on an unknown reason', function () {
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->classified()->raw([
        'user_id' => $user->id,
        'classifier' => [
            'version' => 'draft-1',
            'draft' => true,
            'low_confidence' => false,
            'exclusions' => [['axis' => 'depth', 'feature' => 'x', 'reason' => 'brand_new']],
            'confidence' => null,
        ],
    ]));

    $this->actingAs($user)->getJson("/admin/color/{$profile->id}")
        ->assertOk()
        ->assertJsonPath('classification.exclusions.0.reason_text', 'brand_new');
});

it('shows the palette for both of the top two seasons', function () {
    seedPalettes();
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->classified()->raw(['user_id' => $user->id]));

    $response = $this->actingAs($user)->getJson("/admin/color/{$profile->id}")->assertOk();

    expect(collect($response->json('palettes'))->pluck('slug')->all())
        ->toBe(['deep_winter', 'deep_autumn']);

    $groups = collect($response->json('palettes.0.groups'));
    expect($groups->pluck('name')->all())->toBe(['neutrals', 'base', 'accents', 'avoid']);
    expect($groups->firstWhere('name', 'avoid')['is_recommended'])->toBeFalse();
    expect($groups->firstWhere('name', 'accents')['is_recommended'])->toBeTrue();
    expect($response->json('palettes.0.is_draft'))->toBeTrue();
});

it('puts the hex and the LAB on every swatch', function () {
    /* The hex is what a screen shows and the LAB is what was measured. Neither
     * is useful here without the other. */
    seedPalettes();
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->classified()->raw(['user_id' => $user->id]));

    $swatch = $this->actingAs($user)->getJson("/admin/color/{$profile->id}")
        ->assertOk()
        ->json('palettes.0.groups.0.colors.0');

    expect($swatch['hex'])->toMatch('/^#[0-9A-F]{6}$/');
    expect($swatch['title'])->toContain($swatch['hex'])->toContain('L*');
    expect($swatch['ink'])->toBeIn(['#2D2D2D', '#FFFFFF']);
});

it('says the palette has not been loaded instead of breaking', function () {
    /* The seeder is a command somebody runs. A page that exploded because
     * nobody had run it would be worse than a page that says so. */
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->classified()->raw(['user_id' => $user->id]));

    $this->actingAs($user)->getJson("/admin/color/{$profile->id}")
        ->assertOk()
        ->assertJsonPath('palettes', []);

    $this->actingAs($user)->get('/admin/color?profile='.$profile->id)
        ->assertOk()
        ->assertSee('No palette has been loaded yet', false);
});

it('offers no season and no palette when the engine placed none', function () {
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->measured()->raw(['user_id' => $user->id]));

    $this->actingAs($user)->getJson("/admin/color/{$profile->id}")
        ->assertOk()
        ->assertJsonPath('classification', null)
        ->assertJsonPath('palettes', []);
});

// --- uploading -----------------------------------------------------------

it('creates a profile, parks the photo and queues the analysis', function () {
    Queue::fake();
    $user = admin();

    $response = $this->actingAs($user)->postJson('/admin/color', [
        'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 900),
    ]);

    $response->assertCreated()->assertJsonPath('status', ColorProfile::STATUS_PENDING);

    $profile = ColorProfile::sole();
    expect($profile->user_id)->toBe($user->id)
        ->and($profile->source)->toBe('auto');

    $image = $profile->images()->where('kind', ColorProfileImage::KIND_SOURCE)->sole();
    Storage::disk($image->disk)->assertExists($image->path);
    expect($image->path)->toStartWith((string) config('fitting_room.photo.prefix').'/')
        ->and($image->expires_at->diffInHours(now()))->toBeLessThan(61);

    Queue::assertPushed(AnalyzeColorProfile::class);
});

it('keeps the quiz answers it was given, and drops the ones it was not', function () {
    Queue::fake();

    $this->actingAs(admin())->postJson('/admin/color', [
        'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 900),
        'metal' => 'gold',
        'veins' => 'blue',
    ])->assertCreated();

    expect(ColorProfile::sole()->quiz)->toBe(['metal' => 'gold', 'veins' => 'blue']);
});

it('records no quiz at all when nothing was answered', function () {
    Queue::fake();

    $this->actingAs(admin())->postJson('/admin/color', [
        'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 900),
    ])->assertCreated();

    expect(ColorProfile::sole()->quiz)->toBeNull();
});

it('refuses an answer the engine would not understand', function () {
    Queue::fake();

    $this->actingAs(admin())->postJson('/admin/color', [
        'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 900),
        'metal' => 'bronze',
    ])->assertUnprocessable();

    Queue::assertNothingPushed();
});

it('refuses an upload that is not an image', function () {
    Queue::fake();

    $this->actingAs(admin())->postJson('/admin/color', [
        'photo' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
    ])->assertUnprocessable();

    expect(ColorProfile::count())->toBe(0);
});

it('will not let a member upload even with the flag on', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create())->postJson('/admin/color', [
        'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 900),
    ])->assertNotFound();

    expect(ColorProfile::count())->toBe(0);
    Queue::assertNothingPushed();
});

// --- polling -------------------------------------------------------------

it('reports the state so the page can stop polling', function () {
    $user = admin();
    $pending = ColorProfile::factory()->for($user)->create();
    $done = ColorProfile::factory()->for($user)->measured()->create();

    $this->actingAs($user)->getJson('/admin/color/'.$pending->id)
        ->assertOk()->assertJsonPath('pending', true);

    $this->actingAs($user)->getJson('/admin/color/'.$done->id)
        ->assertOk()->assertJsonPath('pending', false)->assertJsonPath('status', 'ok');
});

it('resolves the measurements server-side, swatch included', function () {
    /* No colour maths in the browser: the page paints what it is handed. */
    $user = admin();
    $profile = ColorProfile::factory()->for($user)->measured()->create();

    $response = $this->actingAs($user)->getJson('/admin/color/'.$profile->id)->assertOk();

    $skin = collect($response->json('zones'))->firstWhere('key', 'skin');
    expect($skin['measured'])->toBeTrue()
        ->and($skin['L'])->toBe(70.1)
        ->and($skin['swatch'])->toMatch('/^#[0-9a-f]{6}$/');
});

it('turns a reject reason into something a person can act on', function () {
    $user = admin();
    $profile = ColorProfile::factory()->for($user)->rejected('low_light')->create();

    $this->actingAs($user)->getJson('/admin/color/'.$profile->id)
        ->assertOk()
        ->assertJsonPath('reject_reason', 'low_light')
        ->assertJsonPath('status', 'rejected');
});

it('does not show one admin another admin profile', function () {
    $mine = admin();
    $theirs = ColorProfile::factory()->for(admin())->create();

    $this->actingAs($mine)->getJson('/admin/color/'.$theirs->id)->assertNotFound();
});

// --- images --------------------------------------------------------------

it('serves a stored image to the administrator who uploaded it', function () {
    $user = admin();
    $profile = ownedProfile($user);

    $this->actingAs($user)
        ->get('/admin/color/'.$profile->id.'/image/source')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
});

it('does not serve an image to a member', function () {
    $user = admin();
    $profile = ownedProfile($user);

    $this->actingAs(User::factory()->create())
        ->get('/admin/color/'.$profile->id.'/image/source')
        ->assertNotFound();
});

it('does not serve an image to a guest', function () {
    config(['features.membership' => true]);
    $profile = ownedProfile(admin());

    $this->get('/admin/color/'.$profile->id.'/image/source')->assertRedirect(route('login'));
});

it('does not serve an image from another admin profile', function () {
    $profile = ownedProfile(admin());

    $this->actingAs(admin())
        ->get('/admin/color/'.$profile->id.'/image/source')
        ->assertNotFound();
});

it('is a 404 once the purge has taken the picture', function () {
    /* Expected, not exceptional: the row and the object both go at 60 hours and
     * the page has to cope with a view that has expired. */
    $user = admin();
    $profile = ColorProfile::factory()->for($user)->measured()->create();

    $this->actingAs($user)
        ->get('/admin/color/'.$profile->id.'/image/overlay')
        ->assertNotFound();
});

it('lists this admin recent profiles and nobody else', function () {
    $user = admin();
    ColorProfile::factory()->for($user)->count(2)->create();
    ColorProfile::factory()->for(admin())->create();

    $this->actingAs($user)->get('/admin/color')
        ->assertOk()
        ->assertViewHas('recent', fn ($recent) => count($recent) === 2);
});

// --- zone status ---------------------------------------------------------

it('shows a row for a zone that was not measured, with the reason', function () {
    /* A blank where a sclera should be tells a reviewer nothing about whether
     * to retake the photo, fix a threshold or fix a mask. */
    $user = admin();
    $profile = ColorProfile::factory()->for($user)->measured()->create([
        'samples' => ['skin' => ['L' => 70.1, 'a' => 12.4, 'b' => 16.8, 'C' => 20.9, 'h' => 53.6, 'pixels' => 4200]],
        'zones' => [
            'skin' => ['usable' => true, 'reason' => null],
            'sclera' => ['usable' => false, 'reason' => 'eye_too_small'],
        ],
    ]);

    $zones = collect(
        $this->actingAs($user)->getJson('/admin/color/'.$profile->id)->assertOk()->json('zones')
    );

    $sclera = $zones->firstWhere('key', 'sclera');
    expect($sclera['measured'])->toBeFalse()
        ->and($sclera['reason'])->toBe('eye_too_small')
        ->and($sclera['reason_text'])->toBe(__('color_zones.eye_too_small'))
        ->and($zones->firstWhere('key', 'skin')['measured'])->toBeTrue();
});

it('falls back to the raw slug for a zone reason it has no copy for', function () {
    $user = admin();
    $profile = ColorProfile::factory()->for($user)->measured()->create([
        'zones' => ['hair' => ['usable' => false, 'reason' => 'something_new']],
    ]);

    $zones = collect($this->actingAs($user)->getJson('/admin/color/'.$profile->id)->json('zones'));

    expect($zones->firstWhere('key', 'hair')['reason_text'])->toBe('something_new');
});

it('marks a zone that was measured and then left out of the calculation', function () {
    /* The live frame: a blown right cheek reported with numbers, and the pooled
     * skin above it computed WITHOUT them. As an ordinary row the page
     * contradicted its own aggregate — three sub-zones listed, two of them
     * behind the figure. */
    $user = admin();
    $sample = fn (float $l, float $a, float $b) => [
        'L' => $l, 'a' => $a, 'b' => $b, 'C' => 12.8, 'h' => 32.6, 'pixels' => 1163,
    ];
    $profile = ColorProfile::factory()->for($user)->measured()->create([
        'samples' => [
            'skin' => $sample(84.831, 10.771, 6.894),
            'skin_parts' => [
                'left_cheek' => $sample(82.784, 14.032, 7.393),
                'right_cheek' => $sample(91.85, 2.187, 0.357),
                'forehead' => $sample(86.877, 7.51, 6.395),
            ],
        ],
        'zones' => [
            'skin' => ['usable' => true, 'reason' => null],
            'left_cheek' => ['usable' => true, 'reason' => null],
            'right_cheek' => ['usable' => false, 'reason' => 'overexposed'],
            'forehead' => ['usable' => true, 'reason' => null],
        ],
    ]);

    $zones = collect($this->actingAs($user)->getJson('/admin/color/'.$profile->id)->assertOk()->json('zones'))
        ->keyBy('key');

    expect($zones['right_cheek']['measured'])->toBeTrue()
        ->and($zones['right_cheek']['L'])->toBe(91.85)
        ->and($zones['right_cheek']['excluded'])->toBeTrue()
        ->and($zones['right_cheek']['reason_text'])->toBe(__('color_zones.overexposed'))
        ->and($zones['left_cheek']['excluded'])->toBeFalse()
        ->and($zones['skin']['excluded'])->toBeFalse();
});

it('marks an excluded zone whatever made it unusable', function () {
    /* The rule is the engine's verdict, not the reason behind it. A sclera both
     * eyes measured and then disagreed about is excluded on exactly the same
     * terms as a blown cheek. */
    $user = admin();
    $profile = ColorProfile::factory()->for($user)->measured()->create([
        'zones' => ['sclera' => ['usable' => false, 'reason' => 'eyes_disagree']],
    ]);

    $sclera = collect($this->actingAs($user)->getJson('/admin/color/'.$profile->id)->json('zones'))
        ->firstWhere('key', 'sclera');

    expect($sclera['measured'])->toBeTrue()
        ->and($sclera['excluded'])->toBeTrue()
        ->and($sclera['reason_text'])->toBe(__('color_zones.eyes_disagree'));
});

it('never shows an excluded zone with no reason beside it', function () {
    /* The same invariant as an unmeasured row: "these numbers were thrown away,
     * and we will not say why" is the one state a reviewer cannot act on. */
    $user = admin();
    $profile = ColorProfile::factory()->for($user)->measured()->create([
        'zones' => ['hair' => ['usable' => false, 'reason' => null]],
    ]);

    $hair = collect($this->actingAs($user)->getJson('/admin/color/'.$profile->id)->json('zones'))
        ->firstWhere('key', 'hair');

    expect($hair['excluded'])->toBeTrue()
        ->and($hair['reason_text'])->toBe(__('color_zones.unexplained_exclusion'));
});

it('shows a rejected eye the numbers it was rejected on', function () {
    /* The engine keeps a rejected side's measurement now, and the page is where
     * that becomes useful: "darker_than_skin" beside L* 66.2 against skin at
     * L* 84.8 says the rule missed by 18, which is a thing a reviewer can
     * argue with. "not measured" was not. */
    $user = admin();
    $eye = fn (float $l) => ['L' => $l, 'a' => 0.4, 'b' => 2.9, 'C' => 2.9, 'h' => 82.1, 'pixels' => 410];
    $profile = ownedProfile($user, ColorProfile::factory()->measured()->raw([
        'user_id' => $user->id,
        'samples' => [
            'skin' => ['L' => 84.8, 'a' => 10.8, 'b' => 6.9, 'C' => 12.8, 'h' => 32.6, 'pixels' => 12944],
            'sclera' => null,
            'sclera_parts' => ['left' => $eye(66.2), 'right' => $eye(64.8)],
        ],
        'zones' => [
            'sclera' => ['usable' => false, 'reason' => 'darker_than_skin'],
            'sclera_left' => ['usable' => false, 'reason' => 'darker_than_skin'],
            'sclera_right' => ['usable' => false, 'reason' => 'darker_than_skin'],
        ],
    ]));

    $zones = collect($this->actingAs($user)->getJson("/admin/color/{$profile->id}")->assertOk()->json('zones'))
        ->keyBy('key');

    expect($zones['sclera_left']['measured'])->toBeTrue()
        ->and($zones['sclera_left']['L'])->toBe(66.2)
        ->and($zones['sclera_left']['excluded'])->toBeTrue()
        ->and($zones['sclera_left']['reason_text'])->toBe(__('color_zones.darker_than_skin'))
        // The pooled row has no numbers because none were computed, so it stays
        // an unmeasured row with a reason rather than an excluded one.
        ->and($zones['sclera']['measured'])->toBeFalse()
        ->and($zones['sclera']['excluded'])->toBeFalse()
        ->and($zones['sclera']['reason_text'])->toBe(__('color_zones.darker_than_skin'));
});

it('says how many sub-zones the pooled skin figure was computed from', function () {
    $user = admin();
    $profile = ColorProfile::factory()->for($user)->measured()->create([
        'zones' => [
            'skin' => ['usable' => true, 'reason' => null],
            'left_cheek' => ['usable' => true, 'reason' => null],
            'right_cheek' => ['usable' => false, 'reason' => 'overexposed'],
            'forehead' => ['usable' => true, 'reason' => null],
        ],
    ]);

    $zones = collect($this->actingAs($user)->getJson('/admin/color/'.$profile->id)->json('zones'))
        ->keyBy('key');

    expect($zones['skin']['pooled_from'])->toBe(['used' => 2, 'of' => 3])
        // Only the aggregate is qualified — a sub-zone row is a measurement of
        // itself and pools nothing.
        ->and($zones['left_cheek']['pooled_from'])->toBeNull();
});

it('counts all three sub-zones when the engine used all three', function () {
    $user = admin();
    $profile = ColorProfile::factory()->for($user)->measured()->create();

    $skin = collect($this->actingAs($user)->getJson('/admin/color/'.$profile->id)->json('zones'))
        ->firstWhere('key', 'skin');

    expect($skin['pooled_from'])->toBe(['used' => 3, 'of' => 3]);
});

it('does not count a sub-zone the engine never measured', function () {
    $user = admin();
    $profile = ColorProfile::factory()->for($user)->measured()->create([
        'samples' => [
            'skin' => ['L' => 70.1, 'a' => 12.4, 'b' => 16.8, 'C' => 20.9, 'h' => 53.6, 'pixels' => 4200],
            'skin_parts' => [
                'left_cheek' => ['L' => 69.4, 'a' => 13.1, 'b' => 17.2, 'C' => 21.6, 'h' => 52.7, 'pixels' => 1100],
            ],
        ],
        'zones' => ['forehead' => ['usable' => false, 'reason' => 'too_few_pixels']],
    ]);

    $skin = collect($this->actingAs($user)->getJson('/admin/color/'.$profile->id)->json('zones'))
        ->firstWhere('key', 'skin');

    expect($skin['pooled_from'])->toBe(['used' => 1, 'of' => 3]);
});

it('draws the exclusion note and the sub-zone count in the table', function () {
    /* The rows are painted by Alpine from the JSON above, so the copy has to be
     * in the template for either to reach a reviewer. */
    $this->actingAs(admin())->get('/admin/color')
        ->assertOk()
        ->assertSee('excluded from the calculation')
        ->assertSee('pooled from ');
});

it('says an axis was never computed on a refused photo', function () {
    /* "every feature behind this axis was excluded" describes a measurement
     * that was attempted and failed. A refused photo never reached the features
     * stage, and saying so sends the reader looking for a mask problem. */
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->rejected('uneven_light')->raw([
        'user_id' => $user->id,
    ]));

    $axes = collect($this->actingAs($user)->getJson("/admin/color/{$profile->id}")
        ->assertOk()->json('axes'));

    expect($axes)->toHaveCount(3);
    foreach ($axes as $axis) {
        expect($axis['value'])->toBeNull()
            ->and($axis['missing_text'])->toBe(__('seasons.axis_not_computed'));
    }
});

it('keeps the excluded-features wording for a measured photo', function () {
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->measured()->raw([
        'user_id' => $user->id,
        'axes' => ['temperature' => null, 'depth' => 0.3, 'chroma' => 0.1],
    ]));

    $axes = collect($this->actingAs($user)->getJson("/admin/color/{$profile->id}")
        ->assertOk()->json('axes'))->keyBy('key');

    expect($axes['temperature']['missing_text'])->toBeNull();
});

it('shows a decoded preview when the upload is one no browser can draw', function () {
    /* A phone sends HEIC. The file is stored and served as it arrived, so the
     * page had a broken image where "As uploaded" should be. The engine returns
     * a decoded view of the frame it actually measured; the page prefers it. */
    $user = admin();
    $profile = ownedProfile($user, ['user_id' => $user->id]);
    foreach (['source_preview', 'overlay'] as $kind) {
        ColorProfileImage::factory()->for($profile, 'profile')->create(['kind' => $kind]);
    }

    $payload = $this->actingAs($user)->getJson("/admin/color/{$profile->id}")->assertOk();

    $payload->assertJsonPath('has_preview', true);
    expect($payload->json('images'))->toContain('source_preview');
});

it('falls back to the raw upload when there is no preview', function () {
    /* Older profiles, and anything the engine refused before it could decode. */
    $user = admin();
    $profile = ownedProfile($user, ['user_id' => $user->id]);

    $this->actingAs($user)->getJson("/admin/color/{$profile->id}")
        ->assertOk()
        ->assertJsonPath('has_preview', false)
        ->assertJsonPath('has_source', true);
});

it('serves the decoded preview only to the administrator who uploaded it', function () {
    /* It is a picture of a face, on exactly the same footing as every other
     * view: through the controller, never from the bucket. */
    $user = admin();
    $profile = ownedProfile($user, ['user_id' => $user->id]);
    $image = ColorProfileImage::factory()->for($profile, 'profile')->create([
        'kind' => 'source_preview',
    ]);
    Storage::disk($image->disk)->put($image->path, 'PNG-BYTES');

    $this->actingAs($user)->get("/admin/color/{$profile->id}/image/source_preview")->assertOk();

    $member = User::factory()->create(['is_admin' => false]);
    $this->actingAs($member)->get("/admin/color/{$profile->id}/image/source_preview")
        ->assertNotFound();
});

it('shows the raw margin and the curve as two separate numbers', function () {
    /* The live frame had two seasons tied at 0.1445 — a gap of exactly 0.000 —
     * under a label reading "how far the first season is ahead of the second:
     * 0.150". Both numbers were real; one was wearing the other's name. */
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->classified()->raw([
        'user_id' => $user->id,
        'classifier' => [
            'version' => 'draft-1',
            'draft' => true,
            'low_confidence' => true,
            'exclusions' => [],
            'confidence' => [
                'margin' => 0.0,
                'from_margin' => 0.15,
                'features_factor' => 0.95,
                'quality_factor' => 0.6,
                'final' => 0.0855,
            ],
        ],
    ]));

    $parts = collect($this->actingAs($user)->getJson("/admin/color/{$profile->id}")
        ->assertOk()->json('classification.confidence_parts'))->keyBy('key');

    expect($parts->keys()->all())
        ->toBe(['margin', 'from_margin', 'features_factor', 'quality_factor', 'final']);
    expect((float) $parts['margin']['value'])->toBe(0.0)
        ->and((float) $parts['from_margin']['value'])->toBe(0.15);
    // And the labels say which is which.
    expect($parts['margin']['label'])->not->toBe($parts['from_margin']['label']);
});

it('says whether white balance was switched on at all', function () {
    /* "method: none" covers three different situations and only one of them is
     * about the photograph. A page that cannot tell them apart sends somebody
     * off to retake a frame that was fine. */
    $user = admin();
    $off = ownedProfile($user, ColorProfile::factory()->measured()->raw([
        'user_id' => $user->id,
        'white_balance' => ['enabled' => false, 'reason' => 'disabled', 'method' => 'none'],
    ]));

    $this->actingAs($user)->getJson("/admin/color/{$off->id}")
        ->assertOk()
        ->assertJsonPath('white_balance.enabled', false)
        ->assertJsonPath('white_balance.applied', false)
        ->assertJsonPath('white_balance.summary', __('color_white_balance.off'));
});

it('says why an enabled white balance did nothing', function () {
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->measured()->raw([
        'user_id' => $user->id,
        'white_balance' => [
            'enabled' => true,
            'reason' => 'sclera_unusable_and_scene_unusable',
            'method' => 'none',
        ],
    ]));

    $this->actingAs($user)->getJson("/admin/color/{$profile->id}")
        ->assertOk()
        ->assertJsonPath('white_balance.enabled', true)
        ->assertJsonPath(
            'white_balance.summary',
            __('color_white_balance.sclera_unusable_and_scene_unusable'),
        );
});

it('does not say "off" about a step that was on but never reached', function () {
    /* A photo refused at detection never reaches white balance, so the engine
     * reports enabled with no reason. Saying "off" there flatly contradicts the
     * `enabled` field printed beside it. */
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->rejected('no_face')->raw([
        'user_id' => $user->id,
        'white_balance' => ['enabled' => true, 'reason' => null, 'method' => 'none'],
    ]));

    $this->actingAs($user)->getJson("/admin/color/{$profile->id}")
        ->assertOk()
        ->assertJsonPath('white_balance.enabled', true)
        ->assertJsonPath('white_balance.summary', __('color_white_balance.not_reached'));
});

it('says so plainly when a correction was applied', function () {
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->measured()->raw([
        'user_id' => $user->id,
        'white_balance' => ['enabled' => true, 'reason' => null, 'method' => 'sclera'],
    ]));

    $this->actingAs($user)->getJson("/admin/color/{$profile->id}")
        ->assertOk()
        ->assertJsonPath('white_balance.applied', true)
        ->assertJsonPath('white_balance.summary', __('color_white_balance.applied'));
});

it('does not claim white balance was on when the engine never said', function () {
    /* Profiles recorded before the engine carried the flag. Absence is not
     * "on" — the engine's own default is off, and guessing the other way puts
     * a claim on the page that nothing supports. */
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->measured()->raw([
        'user_id' => $user->id,
        'white_balance' => ['method' => 'none', 'single_eye' => false],
    ]));

    $this->actingAs($user)->getJson("/admin/color/{$profile->id}")
        ->assertOk()
        ->assertJsonPath('white_balance.enabled', false)
        ->assertJsonPath('white_balance.summary', __('color_white_balance.off'));
});

it('never shows a zone with no numbers and no reason', function () {
    /* The invariant the live frame broke: "Iris, left — not measured", with
     * nothing beside it. The side had passed on geometry so it had no reason to
     * give, and there was nowhere in the contract to put the measurement it had
     * in fact made. Whatever the engine sends, a blank row must not reach the
     * page — that is the one state nobody can act on. */
    $user = admin();
    $profile = ownedProfile($user, ColorProfile::factory()->measured()->raw([
        'user_id' => $user->id,
        // Exactly the shape that produced it: a pooled iris, no sides, and a
        // per-side verdict of "fine".
        'samples' => ['iris' => ['L' => 36.9, 'a' => -3.8, 'b' => -1.3, 'C' => 4.1, 'h' => 198.3, 'pixels' => 292, 'L_iqr' => 8.7]],
        'zones' => [
            'iris' => ['usable' => true, 'reason' => null],
            'iris_left' => ['usable' => true, 'reason' => null],
            'iris_right' => ['usable' => true, 'reason' => null],
        ],
    ]));

    $zones = $this->actingAs($user)->getJson("/admin/color/{$profile->id}")->assertOk()->json('zones');

    foreach ($zones as $zone) {
        if (! $zone['measured']) {
            expect($zone['reason_text'])->not->toBeNull("{$zone['key']} has no sample and no reason");
        }
    }
});

it('shows each eye its own numbers when the engine measured them', function () {
    $user = admin();
    $eye = fn (float $l) => ['L' => $l, 'a' => -3.8, 'b' => -1.3, 'C' => 4.1, 'h' => 198.3, 'pixels' => 150, 'L_iqr' => 8.7];
    $profile = ownedProfile($user, ColorProfile::factory()->measured()->raw([
        'user_id' => $user->id,
        'samples' => [
            'iris' => $eye(36.9),
            'iris_parts' => ['left' => $eye(37.4), 'right' => $eye(36.4)],
        ],
        'zones' => [
            'iris_left' => ['usable' => true, 'reason' => null],
            'iris_right' => ['usable' => true, 'reason' => null],
        ],
    ]));

    $zones = collect($this->actingAs($user)->getJson("/admin/color/{$profile->id}")->json('zones'))
        ->keyBy('key');

    expect($zones['iris_left']['measured'])->toBeTrue()
        ->and($zones['iris_left']['L'])->toBe(37.4)
        ->and($zones['iris_right']['L'])->toBe(36.4);
});

it('lists the eyes one at a time as well as pooled', function () {
    /* Which eye failed is the question a reviewer actually has. */
    $user = admin();
    $profile = ColorProfile::factory()->for($user)->measured()->create([
        'zones' => [
            'sclera' => ['usable' => true, 'reason' => null],
            'sclera_left' => ['usable' => true, 'reason' => null],
            'sclera_right' => ['usable' => false, 'reason' => 'too_dark'],
        ],
    ]);

    $zones = collect($this->actingAs($user)->getJson('/admin/color/'.$profile->id)->json('zones'));

    expect($zones->pluck('key'))->toContain('sclera_left', 'sclera_right', 'iris_left', 'iris_right')
        ->and($zones->firstWhere('key', 'sclera_right')['reason'])->toBe('too_dark');
});

it('says nothing about zones on a profile that never got that far', function () {
    $user = admin();
    $profile = ColorProfile::factory()->for($user)->rejected('no_face')->create();

    $zones = collect($this->actingAs($user)->getJson('/admin/color/'.$profile->id)->json('zones'));

    expect($zones->every(fn ($zone) => $zone['measured'] === false))->toBeTrue()
        ->and($zones->every(fn ($zone) => $zone['reason'] === null))->toBeTrue();
});

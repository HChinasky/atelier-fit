<?php

use App\Models\ColorProfile;
use App\Models\ColorProfileImage;
use App\Models\ColorProfileLabel;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
 * The labelling screen.
 *
 * Two things matter more than the form working. A label must never touch
 * color_profiles.season — the comparison of the engine's answer with a person's
 * is the whole point of collecting these, and the moment one can overwrite the
 * other there is nothing left to compare. And a stylist must edit only their
 * own row: two stylists disagreeing about one face is a signal worth keeping.
 */

beforeEach(function () {
    Storage::fake('r2');
    config(['features.color_analysis' => true, 'privacy.photo_hard_delete_hours' => 60]);
});

function verdict(array $override = []): array
{
    return array_merge([
        'season' => 'true_autumn',
        'stylist_confidence' => 'high',
        'undertone' => 'warm',
        'depth' => 'medium',
        'chroma' => 'soft',
    ], $override);
}

// --- who gets in ---------------------------------------------------------

it('does not exist at all while the feature flag is off', function () {
    config(['features.color_analysis' => false]);

    $this->actingAs(stylist())->get('/stylist/color')->assertNotFound();
});

it('lets a stylist in', function () {
    $this->actingAs(stylist())->get('/stylist/color')->assertOk();
});

it('lets an administrator in', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/stylist/color')
        ->assertOk();
});

it('is a 404 for an ordinary member, never a 403', function () {
    /* 403 confirms the area exists and maps its shape by which paths answer
     * which. One story: if you may not have it, it is not there. */
    $this->actingAs(User::factory()->create())->get('/stylist/color')->assertNotFound();
});

it('sends a guest to sign in', function () {
    $this->get('/stylist/color')->assertRedirect();
});

// --- labelling -----------------------------------------------------------

it('records a verdict', function () {
    $user = stylist();
    $profile = measured();

    $this->actingAs($user)
        ->post("/stylist/color/{$profile->id}", verdict())
        ->assertRedirect(route('stylist.color.index'));

    $label = ColorProfileLabel::sole();
    expect($label->color_profile_id)->toBe($profile->id)
        ->and($label->user_id)->toBe($user->id)
        ->and($label->season)->toBe('true_autumn')
        ->and($label->undertone)->toBe('warm');
});

it('never touches the engine result', function () {
    /* The one invariant this whole feature rests on. */
    $profile = measured(['season' => 'deep_winter']);

    $this->actingAs(stylist())->post("/stylist/color/{$profile->id}", verdict());

    expect($profile->refresh()->season)->toBe('deep_winter');
});

it('overwrites an earlier verdict of its own rather than adding a second', function () {
    $user = stylist();
    $profile = measured();

    $this->actingAs($user)->post("/stylist/color/{$profile->id}", verdict());
    $this->actingAs($user)->post("/stylist/color/{$profile->id}", verdict([
        'season' => 'soft_autumn',
        'stylist_confidence' => 'low',
    ]));

    expect(ColorProfileLabel::count())->toBe(1);
    expect(ColorProfileLabel::sole()->season)->toBe('soft_autumn');
});

it('keeps two stylists disagreeing about the same face', function () {
    /* Two people disagreeing is the honest upper bound on how well any
     * classifier could ever do, so it is data, not a conflict. */
    $profile = measured();

    $this->actingAs(stylist())->post("/stylist/color/{$profile->id}", verdict());
    $this->actingAs(stylist())->post("/stylist/color/{$profile->id}", verdict([
        'season' => 'true_winter',
    ]));

    expect(ColorProfileLabel::where('color_profile_id', $profile->id)->count())->toBe(2);
});

it('cannot edit a label belonging to somebody else', function () {
    $mine = stylist();
    $theirs = stylist();
    $profile = measured();
    $this->actingAs($theirs)->post("/stylist/color/{$profile->id}", verdict([
        'season' => 'true_winter',
    ]));

    $this->actingAs($mine)->post("/stylist/color/{$profile->id}", verdict([
        'season' => 'soft_summer',
    ]));

    expect(ColorProfileLabel::where('user_id', $theirs->id)->sole()->season)->toBe('true_winter');
    expect(ColorProfileLabel::where('user_id', $mine->id)->sole()->season)->toBe('soft_summer');
});

it('requires a season and a confidence', function () {
    /* An unqualified verdict and a guess weigh the same in a report that cannot
     * tell them apart. */
    $profile = measured();

    $this->actingAs(stylist())
        ->post("/stylist/color/{$profile->id}", ['season' => 'true_autumn'])
        ->assertSessionHasErrors('stylist_confidence');

    $this->actingAs(stylist())
        ->post("/stylist/color/{$profile->id}", ['stylist_confidence' => 'high'])
        ->assertSessionHasErrors('season');
});

it('refuses a season the engine cannot produce', function () {
    /* The two vocabularies have to stay one, or the report compares nothing. */
    $profile = measured();

    $this->actingAs(stylist())
        ->post("/stylist/color/{$profile->id}", verdict(['season' => 'early_autumn']))
        ->assertSessionHasErrors('season');
});

it('refuses a second season the same as the first', function () {
    $profile = measured();

    $this->actingAs(stylist())
        ->post("/stylist/color/{$profile->id}", verdict(['season_alt' => 'true_autumn']))
        ->assertSessionHasErrors('season_alt');
});

it('accepts a verdict with the axes left blank', function () {
    /* A stylist sure about the season and unsure about the undertone should be
     * able to say exactly that; a forced guess becomes a number in an anchor. */
    $profile = measured();

    $this->actingAs(stylist())->post("/stylist/color/{$profile->id}", [
        'season' => 'true_autumn',
        'stylist_confidence' => 'medium',
        'undertone' => '',
        'depth' => '',
    ])->assertRedirect();

    $label = ColorProfileLabel::sole();
    expect($label->undertone)->toBeNull()->and($label->depth)->toBeNull();
});

it('will not label a profile the engine refused', function () {
    /* No measurements to pair a verdict with. */
    $refused = ColorProfile::factory()->rejected()->create();

    $this->actingAs(stylist())->get("/stylist/color/{$refused->id}")->assertNotFound();
    $this->actingAs(stylist())->post("/stylist/color/{$refused->id}", verdict())->assertNotFound();
});

// --- the photograph ------------------------------------------------------

it('shows a stylist the photo of a profile they did not upload', function () {
    /* The opposite of the rule on /admin/color, and deliberately so: the job is
     * to judge faces somebody else photographed. Open question: third-party
     * access to a member's photo is its own purpose of processing. */
    $profile = measured();
    $image = ColorProfileImage::factory()->for($profile, 'profile')->create([
        'kind' => 'source_preview',
    ]);
    Storage::disk($image->disk)->put($image->path, 'PNG-BYTES');

    $this->actingAs(stylist())
        ->get("/stylist/color/{$profile->id}/image/source_preview")
        ->assertOk();
});

it('does not show the photo to an ordinary member', function () {
    $profile = measured();
    $image = ColorProfileImage::factory()->for($profile, 'profile')->create([
        'kind' => 'source_preview',
    ]);
    Storage::disk($image->disk)->put($image->path, 'PNG-BYTES');

    $this->actingAs(User::factory()->create())
        ->get("/stylist/color/{$profile->id}/image/source_preview")
        ->assertNotFound();
});

it('still allows labelling once the photo has been purged', function () {
    /* Nothing here extends the life of a photo. After the purge the
     * measurements and the label are still a complete row of the dataset. */
    $profile = measured();

    $this->actingAs(stylist())->get("/stylist/color/{$profile->id}")
        ->assertOk()
        ->assertSee('has been deleted', false);

    $this->actingAs(stylist())->post("/stylist/color/{$profile->id}", verdict())
        ->assertRedirect();
});

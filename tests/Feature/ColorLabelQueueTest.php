<?php

use App\Models\ColorProfile;
use App\Models\ColorProfileLabel;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * The labelling queue.
 *
 * Its job is to answer "what is left" for one person, which is why every filter
 * and every count is scoped to the signed-in stylist: another stylist working
 * through the same profiles must not make this one's queue look finished.
 */

beforeEach(function () {
    Storage::fake('r2');
    config(['features.color_analysis' => true]);
});

function queueFor(User $user, array $query = []): TestResponse
{
    return test()->actingAs($user)->get('/stylist/color?'.http_build_query($query));
}

function labelled(ColorProfile $profile, User $user, string $season = 'true_autumn'): ColorProfileLabel
{
    return ColorProfileLabel::factory()->create([
        'color_profile_id' => $profile->id,
        'user_id' => $user->id,
        'season' => $season,
    ]);
}

it('lists measured profiles that this stylist has not labelled', function () {
    $user = stylist();
    $waiting = measured();
    $done = measured();
    labelled($done, $user);

    $response = queueFor($user);

    $response->assertOk()->assertSee((string) $waiting->id, false);
    expect($response->viewData('profiles'))->toHaveCount(1);
});

it('counts what is done and what is left, for this stylist alone', function () {
    /* Another stylist working the same profiles must not make this queue look
     * finished. */
    $mine = stylist();
    $theirs = stylist();
    [$first, $second, $third] = [measured(), measured(), measured()];
    labelled($first, $mine);
    labelled($second, $theirs);
    labelled($third, $theirs);

    $counts = queueFor($mine)->assertOk()->viewData('counts');

    expect($counts['total'])->toBe(3)
        ->and($counts['labelled'])->toBe(1)
        ->and($counts['remaining'])->toBe(2);
});

it('leaves out profiles the engine refused', function () {
    /* Nothing to pair a verdict with. */
    $user = stylist();
    measured();
    ColorProfile::factory()->rejected()->create();

    expect(queueFor($user)->viewData('profiles'))->toHaveCount(1);
});

it('filters to what this stylist has already labelled', function () {
    $user = stylist();
    $done = measured();
    measured();
    labelled($done, $user);

    $rows = queueFor($user, ['labelled' => 'yes'])->assertOk()->viewData('profiles');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['id'])->toBe($done->id);
});

it('filters to everything', function () {
    $user = stylist();
    labelled(measured(), $user);
    measured();

    expect(queueFor($user, ['labelled' => 'any'])->viewData('profiles'))->toHaveCount(2);
});

it('filters to profiles where the stylist and the engine differ', function () {
    $user = stylist();
    $agrees = measured(['season' => 'true_autumn']);
    $differs = measured(['season' => 'deep_winter']);
    labelled($agrees, $user, 'true_autumn');
    labelled($differs, $user, 'true_autumn');

    $rows = queueFor($user, ['labelled' => 'any', 'agreement' => 'differ'])->viewData('profiles');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['id'])->toBe($differs->id);
});

it('filters to profiles where they agree', function () {
    $user = stylist();
    $agrees = measured(['season' => 'true_autumn']);
    $differs = measured(['season' => 'deep_winter']);
    labelled($agrees, $user, 'true_autumn');
    labelled($differs, $user, 'true_autumn');

    $rows = queueFor($user, ['labelled' => 'any', 'agreement' => 'match'])->viewData('profiles');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['id'])->toBe($agrees->id);
});

it('treats an unlabelled profile as neither agreeing nor differing', function () {
    /* "We have not compared them" is not "they disagree", and must not filter
     * as though it were. */
    $user = stylist();
    measured(['season' => 'deep_winter']);

    expect(queueFor($user, ['labelled' => 'any', 'agreement' => 'differ'])->viewData('profiles'))
        ->toHaveCount(0);
    expect(queueFor($user, ['labelled' => 'any', 'agreement' => 'match'])->viewData('profiles'))
        ->toHaveCount(0);
});

it('filters to the profiles the engine was unsure about', function () {
    /* The ones worth a person's time first: where the engine already says it
     * does not know, a verdict is worth more. */
    $user = stylist();
    $unsure = measured();
    $unsure->update(['classifier' => ['version' => 'draft-2', 'draft' => true, 'low_confidence' => true]]);
    $sure = measured();
    $sure->update(['classifier' => ['version' => 'draft-2', 'draft' => true, 'low_confidence' => false]]);

    $rows = queueFor($user, ['low_confidence' => 'yes'])->assertOk()->viewData('profiles');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['id'])->toBe($unsure->id);
});

it('filters to every shot of one person', function () {
    /* Several lightings of one face is what makes white balance measurable, so
     * seeing them together is the point of the handle. */
    $user = stylist();
    ColorProfile::factory()->create(
        ColorProfile::factory()->classified()->forPerson('p001', 'daylight')->raw()
    );
    ColorProfile::factory()->create(
        ColorProfile::factory()->classified()->forPerson('p001', 'flash')->raw()
    );
    ColorProfile::factory()->create(
        ColorProfile::factory()->classified()->forPerson('p002')->raw()
    );

    $rows = queueFor($user, ['person_ref' => 'p001'])->assertOk()->viewData('profiles');

    expect($rows)->toHaveCount(2);
    expect(collect($rows)->pluck('lighting')->sort()->values()->all())
        ->toBe(['daylight', 'flash']);
});

it('does not show the engine answer beside a profile nobody has labelled', function () {
    /* The labelling screen hides it to stop a stylist anchoring on it. A queue
     * that printed it in a column would undo that on the way in. */
    $user = stylist();
    measured(['season' => 'deep_winter']);

    $rows = queueFor($user)->assertOk()->viewData('profiles');

    expect($rows[0]['labelled'])->toBeFalse();
    queueFor($user)->assertDontSee('Deep winter', false);
});

it('shows the engine answer once the profile has been labelled', function () {
    $user = stylist();
    labelled(measured(['season' => 'deep_winter']), $user, 'true_autumn');

    queueFor($user, ['labelled' => 'any'])
        ->assertOk()
        ->assertSee('Deep winter', false)
        ->assertSee('True autumn', false);
});

it('ignores a filter value nobody offered', function () {
    $user = stylist();
    measured();

    queueFor($user, ['labelled' => 'perhaps', 'agreement' => 'sideways'])->assertOk();
});

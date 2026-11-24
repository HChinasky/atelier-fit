<?php

use App\Models\Season;
use App\Models\SeasonColor;

/*
 * The seeder that loads the DRAFT palettes.
 *
 * Two properties matter more than the colours themselves: running it twice must
 * change nothing, and it must never touch a row a stylist chose. The second is
 * the reason the first is safe to rely on — "re-run the seeder" has to be a
 * sentence somebody can say without checking what they are about to lose.
 */

it('loads all twelve seasons and their four groups', function () {
    seedPalettes();

    expect(Season::count())->toBe(12);

    $season = Season::where('slug', 'deep_winter')->firstOrFail();
    expect($season->colors()->pluck('group')->unique()->sort()->values()->all())
        ->toBe(['accents', 'avoid', 'base', 'neutrals']);
});

it('marks every generated colour as a draft the generator owns', function () {
    seedPalettes();

    expect(SeasonColor::where('is_draft', false)->count())->toBe(0);
    expect(SeasonColor::where('source', '!=', SeasonColor::SOURCE_GENERATOR)->count())->toBe(0);
});

it('stores a colour a screen can show and the LAB beside it', function () {
    seedPalettes();

    $color = SeasonColor::first();

    expect($color->hex)->toMatch('/^#[0-9A-F]{6}$/');
    expect($color->lab_l)->toBeGreaterThan(0.0)->toBeLessThanOrEqual(100.0);
});

it('only the avoid group is not recommended', function () {
    seedPalettes();

    expect(SeasonColor::where('is_recommended', false)->pluck('group')->unique()->all())
        ->toBe(['avoid']);
});

it('changes nothing when it runs twice', function () {
    seedPalettes();
    $before = SeasonColor::orderBy('id')->get(['id', 'hex', 'group', 'sort'])->toArray();

    seedPalettes();

    expect(SeasonColor::orderBy('id')->get(['id', 'hex', 'group', 'sort'])->toArray())
        ->toBe($before);
});

it('never touches a colour a stylist chose', function () {
    seedPalettes();
    $season = Season::where('slug', 'true_summer')->firstOrFail();

    $chosen = SeasonColor::factory()->stylist()->create([
        'season_id' => $season->id,
        'hex' => '#123456',
        'group' => SeasonColor::GROUP_ACCENTS,
        'sort' => 0,
    ]);

    seedPalettes();

    $chosen->refresh();
    expect($chosen->hex)->toBe('#123456');
    expect($chosen->is_draft)->toBeFalse();
    expect($chosen->source)->toBe(SeasonColor::SOURCE_STYLIST);
});

it('removes a generated colour the file no longer produces', function () {
    seedPalettes();
    $season = Season::where('slug', 'true_summer')->firstOrFail();

    // A generator row at a position the file does not fill.
    $stale = SeasonColor::factory()->create([
        'season_id' => $season->id,
        'group' => SeasonColor::GROUP_ACCENTS,
        'sort' => 97,
        'source' => SeasonColor::SOURCE_GENERATOR,
    ]);

    seedPalettes();

    expect(SeasonColor::find($stale->id))->toBeNull();
});

it('reads the name out of lang rather than out of the table', function () {
    seedPalettes();
    app()->setLocale('en');

    expect(Season::where('slug', 'deep_winter')->firstOrFail()->name())->toBe('Deep winter');
});

it('falls back to a readable name rather than to silence', function () {
    // A slug nothing translates. An empty heading is a bug nobody can see.
    $season = Season::factory()->create(['slug' => 'not_a_season']);

    expect($season->name())->toBe('Not A Season');
    expect($season->description())->toBeNull();
});

it('picks ink a person can actually read off the swatch', function () {
    $light = SeasonColor::factory()->make(['lab_l' => 88.0]);
    $dark = SeasonColor::factory()->make(['lab_l' => 22.0]);

    expect($light->readableInk())->toBe('#2D2D2D');
    expect($dark->readableInk())->toBe('#FFFFFF');
});

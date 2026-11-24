<?php

use App\Models\Season;
use App\Models\SeasonColor;
use Database\Seeders\StylistPaletteSeeder;

/*
 * The stylist's own palettes (database/seeders/data/stylist_palettes.json),
 * read off her chart. The properties that matter: they replace the generated
 * colours in the groups they fill, the generated `avoid` group stays (her chart
 * has none), a rerun changes nothing, and the draft generator cannot put its
 * colours back beside hers.
 */

function seedStylistPalettes(): void
{
    test()->seed(StylistPaletteSeeder::class);
}

function stylistDocument(): array
{
    return json_decode((string) file_get_contents(database_path(StylistPaletteSeeder::DATA_PATH)), true);
}

it('has a palette for every one of the twelve seasons, in valid colours', function () {
    $document = stylistDocument();

    expect($document['seasons'])->toHaveCount(12);

    foreach ($document['seasons'] as $season) {
        $colours = collect($season['groups'])->flatMap(fn ($group) => $group['colors']);

        expect($colours->count())->toBeGreaterThanOrEqual(24, $season['slug']);
        expect($colours->pluck('hex')->unique()->count())->toBe($colours->count(), $season['slug']);

        foreach ($colours as $colour) {
            expect($colour['hex'])->toMatch('/^#[0-9A-F]{6}$/');
            expect($colour['lab']['L'])->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(100.0);
        }
    }
});

it('keeps the LAB beside each colour in step with its hex', function () {
    // L* of a swatch is a function of its rgb; a hand-edited hex that left the
    // LAB behind would match garments against a colour nobody sees.
    foreach (stylistDocument()['seasons'] as $season) {
        foreach ($season['groups'] as $group) {
            foreach ($group['colors'] as $colour) {
                [$r, $g, $b] = array_map(fn ($c) => hexdec($c) / 255, str_split(substr($colour['hex'], 1), 2));
                $linear = fn ($c) => $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
                $y = 0.2126729 * $linear($r) + 0.7151522 * $linear($g) + 0.0721750 * $linear($b);
                $l = $y > 216 / 24389 ? 116 * $y ** (1 / 3) - 16 : 24389 / 27 * $y;

                expect(abs($l - $colour['lab']['L']))->toBeLessThan(0.1, $colour['hex']);
            }
        }
    }
});

it('loads her colours as source stylist, not drafts', function () {
    seedStylistPalettes();

    expect(Season::count())->toBe(12);
    expect(SeasonColor::where('source', '!=', SeasonColor::SOURCE_STYLIST)->count())->toBe(0);
    expect(SeasonColor::where('is_draft', true)->count())->toBe(0);
    expect(SeasonColor::pluck('group')->unique()->sort()->values()->all())
        ->toBe(['accents', 'base', 'neutrals']);
});

it('replaces the generated colours in the groups it fills and keeps the generated avoid group', function () {
    seedPalettes();
    seedStylistPalettes();

    $season = Season::where('slug', 'true_autumn')->firstOrFail();

    foreach (['neutrals', 'base', 'accents'] as $group) {
        expect($season->colors()->where('group', $group)->pluck('source')->unique()->all())
            ->toBe([SeasonColor::SOURCE_STYLIST], $group);
    }

    $avoid = $season->colors()->where('group', 'avoid')->get();
    expect($avoid)->not->toBeEmpty();
    expect($avoid->pluck('source')->unique()->all())->toBe([SeasonColor::SOURCE_GENERATOR]);
    expect($avoid->every(fn (SeasonColor $c) => ! $c->is_recommended))->toBeTrue();
});

it('changes nothing when it runs twice', function () {
    seedStylistPalettes();
    $before = SeasonColor::orderBy('id')->get(['id', 'hex', 'group', 'sort'])->toArray();

    seedStylistPalettes();

    expect(SeasonColor::orderBy('id')->get(['id', 'hex', 'group', 'sort'])->toArray())->toBe($before);
});

it('is not undone by running the draft generator afterwards', function () {
    seedPalettes();
    seedStylistPalettes();
    $before = SeasonColor::where('source', SeasonColor::SOURCE_STYLIST)->count();

    seedPalettes();

    expect(SeasonColor::where('source', SeasonColor::SOURCE_STYLIST)->count())->toBe($before);
    expect(SeasonColor::where('source', SeasonColor::SOURCE_GENERATOR)->pluck('group')->unique()->all())
        ->toBe(['avoid']);
});

it('drops a stale stylist colour left from an earlier, longer file', function () {
    seedStylistPalettes();
    $season = Season::where('slug', 'light_spring')->firstOrFail();

    $stale = SeasonColor::factory()->stylist()->create([
        'season_id' => $season->id,
        'group' => SeasonColor::GROUP_ACCENTS,
        'sort' => 97,
    ]);

    seedStylistPalettes();

    expect(SeasonColor::find($stale->id))->toBeNull();
});

it('gives every season a usable amount of each group, warm seasons included', function () {
    // A chroma-only split once left deep autumn with two neutrals: cream, chocolate
    // and navy carry more chroma than a grey does. The page lays the groups side by
    // side, and a group of two beside a group of fourteen reads as a missing palette.
    foreach (stylistDocument()['seasons'] as $season) {
        foreach ($season['groups'] as $group) {
            expect(count($group['colors']))
                ->toBeGreaterThanOrEqual(6, $season['slug'].' '.$group['name'])
                ->toBeLessThanOrEqual(13, $season['slug'].' '.$group['name']);
        }
    }
});

it('counts cream and dark brown as neutrals in deep autumn', function () {
    $deepAutumn = collect(stylistDocument()['seasons'])->firstWhere('slug', 'deep_autumn');
    $neutrals = collect($deepAutumn['groups'])->firstWhere('name', 'neutrals')['colors'];

    expect(collect($neutrals)->pluck('hex')->all())
        ->toContain('#F0E0CB')   // cream
        ->toContain('#311B10');  // chocolate
});

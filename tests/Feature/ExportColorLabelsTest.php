<?php

use App\Models\ColorProfileImage;
use App\Models\ColorProfileLabel;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
 * The calibration export.
 *
 * The property that matters is negative: this file is meant to be opened in a
 * notebook and argued with, which means it gets copied onto laptops, which
 * means it must contain no people. Measurements and verdicts, nothing else.
 */

beforeEach(function () {
    Storage::fake('local');
    config(['features.color_analysis' => true]);
});

function exported(array $options = []): array
{
    test()->artisan('color:export-labels', $options)->assertSuccessful();

    $files = Storage::disk('local')->allFiles();
    expect($files)->toHaveCount(1);

    return json_decode(Storage::disk('local')->get($files[0]), true);
}

it('writes a row for every label', function () {
    $stylist = stylist();
    ColorProfileLabel::factory()->create([
        'color_profile_id' => measured()->id,
        'user_id' => $stylist->id,
    ]);
    ColorProfileLabel::factory()->create([
        'color_profile_id' => measured()->id,
        'user_id' => $stylist->id,
    ]);

    $document = exported();

    expect($document['rows'])->toBe(2)->and($document['labels'])->toHaveCount(2);
});

it('carries everything the engine measured', function () {
    ColorProfileLabel::factory()->create([
        'color_profile_id' => measured()->id,
        'user_id' => stylist()->id,
    ]);

    $row = exported()['labels'][0];

    foreach (['samples', 'zones', 'axes', 'contrast', 'white_balance', 'quiz'] as $section) {
        expect($row)->toHaveKey($section);
    }
    // Sub-zones too: the cheeks and the forehead separately are most of what
    // the anchor points will be set from.
    expect($row['samples']['skin_parts'])->toHaveKeys(['left_cheek', 'right_cheek', 'forehead']);
});

it('carries what the engine concluded and which version concluded it', function () {
    /* Without the versions a row is a claim with no date on it: the anchors
     * live in a file that changes. */
    ColorProfileLabel::factory()->create([
        'color_profile_id' => measured()->id,
        'user_id' => stylist()->id,
    ]);

    $engine = exported()['labels'][0]['engine'];

    expect($engine['seasons'])->toHaveCount(2)
        ->and($engine['algo_version'])->toBe('0.1.0')
        ->and($engine['classifier_version'])->toBe('draft-1')
        ->and($engine)->toHaveKey('confidence');
});

it('carries the whole verdict', function () {
    ColorProfileLabel::factory()->create([
        'color_profile_id' => measured()->id,
        'user_id' => stylist()->id,
        'season' => 'true_autumn',
        'season_alt' => 'soft_autumn',
        'undertone' => 'warm',
        'depth' => 'medium',
        'chroma' => 'soft',
        'stylist_confidence' => 'medium',
        'notes' => 'freckles, natural red hair',
    ]);

    $stylist = exported()['labels'][0]['stylist'];

    expect($stylist['season'])->toBe('true_autumn')
        ->and($stylist['season_alt'])->toBe('soft_autumn')
        ->and($stylist['undertone'])->toBe('warm')
        ->and($stylist['stylist_confidence'])->toBe('medium')
        ->and($stylist['notes'])->toBe('freckles, natural red hair');
});

it('carries the person handle when there is one', function () {
    /* Several shots of one face under different lights is what makes white
     * balance measurable, and the handle is the only thing that links them. */
    $profile = measured();
    $profile->update(['person_ref' => 'p001', 'lighting' => 'indoor_warm']);
    ColorProfileLabel::factory()->create([
        'color_profile_id' => $profile->id,
        'user_id' => stylist()->id,
    ]);

    $row = exported()['labels'][0];

    expect($row['person_ref'])->toBe('p001')->and($row['lighting'])->toBe('indoor_warm');
});

// --- what must NOT be in it ----------------------------------------------

it('contains no account, no contact details and no file paths', function () {
    /* The whole point of the file is that it can be copied onto a laptop. */
    $stylist = User::factory()->create([
        'is_stylist' => true,
        'name' => 'Atelier Fit',
        'email' => 'stylist@example.test',
    ]);
    $owner = User::factory()->create(['email' => 'subject@example.test', 'name' => 'Subject Person']);
    $profile = measured(['user_id' => $owner->id]);
    ColorProfileImage::factory()->for($profile, 'profile')->create();
    ColorProfileLabel::factory()->create([
        'color_profile_id' => $profile->id,
        'user_id' => $stylist->id,
    ]);

    exported();
    $raw = Storage::disk('local')->get(Storage::disk('local')->allFiles()[0]);

    foreach ([
        'Atelier Fit', 'Subject Person',
        'stylist@example.test', 'subject@example.test',
        'user_id', 'phone', 'client_photo', '.jpg', '.png',
    ] as $forbidden) {
        expect($raw)->not->toContain($forbidden);
    }
});

it('says on its face that it holds no personal data', function () {
    /* So that somebody finding the file in six months does not have to read the
     * exporter to find out. */
    ColorProfileLabel::factory()->create([
        'color_profile_id' => measured()->id,
        'user_id' => stylist()->id,
    ]);

    $document = exported();

    expect($document['contains_personal_data'])->toBeFalse();
    expect($document['note'])->toContain('No account');
});

// --- housekeeping --------------------------------------------------------

it('writes into private storage by default', function () {
    ColorProfileLabel::factory()->create([
        'color_profile_id' => measured()->id,
        'user_id' => stylist()->id,
    ]);

    $this->artisan('color:export-labels')->assertSuccessful();

    expect(Storage::disk('local')->allFiles()[0])->toStartWith('color-labels/');
});

it('accepts the path the runbook prints', function () {
    ColorProfileLabel::factory()->create([
        'color_profile_id' => measured()->id,
        'user_id' => stylist()->id,
    ]);

    $this->artisan('color:export-labels', [
        '--out' => 'storage/app/private/color-labels/run.json',
    ])->assertSuccessful();

    expect(Storage::disk('local')->exists('color-labels/run.json'))->toBeTrue();
});

it('says so rather than writing an empty file when nothing is labelled', function () {
    $this->artisan('color:export-labels')
        ->expectsOutputToContain('Nothing has been labelled yet.')
        ->assertSuccessful();

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('exports both verdicts when two stylists labelled one face', function () {
    /* Disagreement is the signal, so it has to reach the dataset. */
    $profile = measured();
    ColorProfileLabel::factory()->create([
        'color_profile_id' => $profile->id,
        'user_id' => stylist()->id,
        'season' => 'true_autumn',
    ]);
    ColorProfileLabel::factory()->create([
        'color_profile_id' => $profile->id,
        'user_id' => stylist()->id,
        'season' => 'soft_autumn',
    ]);

    $document = exported();

    expect($document['rows'])->toBe(2)->and($document['profiles'])->toBe(1);
    expect(collect($document['labels'])->pluck('stylist.season')->sort()->values()->all())
        ->toBe(['soft_autumn', 'true_autumn']);
});

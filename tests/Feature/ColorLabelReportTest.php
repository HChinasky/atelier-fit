<?php

use App\Models\ColorProfile;
use App\Models\ColorProfileLabel;
use App\Services\Color\LabelReport;
use Illuminate\Support\Facades\Storage;

/*
 * The match report.
 *
 * It computes and does not conclude: no threshold is changed by it and none is
 * proposed. What it has to get right is the arithmetic, because the anchor
 * points eventually get set from these numbers by a person reading them.
 */

beforeEach(function () {
    Storage::fake('local');
    config(['features.color_analysis' => true]);
});

function labelProfile(string $engine, string $stylist, array $extra = []): void
{
    $profile = measured(array_merge([
        'season' => $engine,
        'seasons' => [['slug' => $engine, 'score' => 0.3], ['slug' => 'true_summer', 'score' => 0.2]],
    ], $extra['profile'] ?? []));

    ColorProfileLabel::factory()->create(array_merge([
        'color_profile_id' => $profile->id,
        'user_id' => stylist()->id,
        'season' => $stylist,
    ], $extra['label'] ?? []));
}

function reportFor(): array
{
    return app(LabelReport::class)->build();
}

// --- agreement -----------------------------------------------------------

it('counts exact agreement', function () {
    labelProfile('true_autumn', 'true_autumn');
    labelProfile('deep_winter', 'true_autumn');

    $agreement = reportFor()['all']['agreement'];

    expect($agreement['exact']['hits'])->toBe(1)
        ->and($agreement['exact']['of'])->toBe(2)
        ->and($agreement['exact']['rate'])->toBe(0.5);
});

it('counts the stylist landing in the engine top two', function () {
    /* The engine returns two on purpose, so judging it on the first alone
     * reports on a product nobody built. */
    labelProfile('deep_winter', 'true_summer');   // true_summer is the runner-up

    $agreement = reportFor()['all']['agreement'];

    expect($agreement['exact']['hits'])->toBe(0)
        ->and($agreement['within_top_2']['hits'])->toBe(1);
});

it('counts family agreement by the end of the slug', function () {
    /* Calling an autumn a winter is a different kind of wrong from calling a
     * true autumn a deep autumn, and an aggregate that cannot tell them apart
     * hides which one is happening. */
    labelProfile('deep_autumn', 'true_autumn');   // same family, wrong season
    labelProfile('deep_winter', 'true_autumn');   // wrong family

    $agreement = reportFor()['all']['agreement'];

    expect($agreement['exact']['hits'])->toBe(0)
        ->and($agreement['same_family']['hits'])->toBe(1)
        ->and($agreement['same_family']['of'])->toBe(2);
});

it('counts agreement on an axis only where the stylist judged it', function () {
    /* Left blank means "not judged", which is not a disagreement. */
    labelProfile('true_autumn', 'true_autumn', [
        'profile' => ['axes' => ['temperature' => 0.8, 'depth' => 0.0, 'chroma' => 0.0]],
        'label' => ['undertone' => 'warm'],
    ]);
    labelProfile('true_autumn', 'true_autumn', [
        'profile' => ['axes' => ['temperature' => 0.8, 'depth' => 0.0, 'chroma' => 0.0]],
        'label' => ['undertone' => null],
    ]);

    $undertone = reportFor()['all']['agreement']['axes']['undertone'];

    expect($undertone['judged'])->toBe(1)
        ->and($undertone['hits'])->toBe(1);
});

it('reads the engine axes into the stylist vocabulary by thirds', function () {
    labelProfile('true_winter', 'true_winter', [
        'profile' => ['axes' => ['temperature' => -0.9, 'depth' => 0.0, 'chroma' => 0.0]],
        'label' => ['undertone' => 'cool'],
    ]);
    labelProfile('true_winter', 'true_winter', [
        'profile' => ['axes' => ['temperature' => 0.05, 'depth' => 0.0, 'chroma' => 0.0]],
        'label' => ['undertone' => 'neutral'],
    ]);

    expect(reportFor()['all']['agreement']['axes']['undertone']['hits'])->toBe(2);
});

// --- the matrix ----------------------------------------------------------

it('builds a full twelve by twelve matrix whatever was labelled', function () {
    /* A matrix with rows missing cannot be read as a matrix. */
    labelProfile('deep_winter', 'true_autumn');

    $confusion = reportFor()['all']['confusion'];

    expect($confusion)->toHaveCount(12);
    foreach ($confusion as $row) {
        expect($row)->toHaveCount(12);
    }
    expect($confusion['true_autumn']['deep_winter'])->toBe(1);
    expect($confusion['true_autumn']['true_autumn'])->toBe(0);
});

// --- distributions -------------------------------------------------------

it('reports where the measurements land, grouped by the stylist verdict', function () {
    /* The raw material the reference points get set from. */
    labelProfile('true_autumn', 'true_autumn', ['label' => ['undertone' => 'warm']]);

    $distributions = reportFor()['all']['distributions'];

    expect($distributions)->toHaveKeys(['season', 'undertone', 'depth', 'chroma']);
    $warm = $distributions['undertone']['warm'];
    expect($warm['n'])->toBe(1);
    expect($warm['features'])->toHaveKeys(['skin_h', 'skin_c', 'skin_l', 'hair_l', 'contrast']);
    expect($warm['features']['skin_h']['median'])->toBeFloat();
});

it('says nothing rather than zero when a measurement is missing', function () {
    labelProfile('true_autumn', 'true_autumn', [
        'profile' => ['samples' => ['skin' => null], 'contrast' => null],
        'label' => ['undertone' => 'warm'],
    ]);

    $features = reportFor()['all']['distributions']['undertone']['warm']['features'];

    expect($features['skin_h']['n'])->toBe(0)
        ->and($features['skin_h']['median'])->toBeNull();
});

// --- worst ---------------------------------------------------------------

it('puts a confident family mismatch at the top of what to look at', function () {
    /* A wrong answer the engine was sure about is where a threshold is actually
     * misplaced; one it already flagged is the system working. */
    labelProfile('deep_autumn', 'true_autumn', ['profile' => ['confidence' => 0.9]]);
    labelProfile('deep_winter', 'true_autumn', ['profile' => ['confidence' => 0.2]]);

    $worst = reportFor()['all']['worst'];

    expect($worst)->toHaveCount(2);
    expect($worst[0]['engine_season'])->toBe('deep_winter');
    expect($worst[0]['family_mismatch'])->toBeTrue();
});

it('leaves agreements out of the list of things to look at', function () {
    labelProfile('true_autumn', 'true_autumn');

    expect(reportFor()['all']['worst'])->toBeEmpty();
});

// --- the corrected subset ------------------------------------------------

it('reports the corrected frames separately', function () {
    /* Half the point of a calibration set is to find out whether the correction
     * helps, and an aggregate over both kinds cannot answer that. */
    labelProfile('true_autumn', 'true_autumn', [
        'profile' => ['white_balance' => ['method' => 'sclera', 'enabled' => true]],
    ]);
    labelProfile('deep_winter', 'true_autumn', [
        'profile' => ['white_balance' => ['method' => 'none', 'enabled' => false]],
    ]);

    $report = reportFor();

    expect($report['all']['labelled'])->toBe(2)
        ->and($report['corrected']['labelled'])->toBe(1)
        ->and($report['corrected']['agreement']['exact']['rate'])->toBe(1.0);
});

// --- the command ---------------------------------------------------------

it('writes the page and the summary beside it', function () {
    labelProfile('true_autumn', 'true_autumn');

    $this->artisan('color:label-report')->assertSuccessful();

    $files = Storage::disk('local')->allFiles();
    expect($files)->toHaveCount(2);
    expect(collect($files)->filter(fn ($f) => str_ends_with($f, '.summary.json')))->toHaveCount(1);
    expect(collect($files)->filter(fn ($f) => str_ends_with($f, '.html')))->toHaveCount(1);
});

it('renders a page that says it concluded nothing', function () {
    labelProfile('deep_winter', 'true_autumn');

    $this->artisan('color:label-report')->assertSuccessful();

    $html = Storage::disk('local')->get(
        collect(Storage::disk('local')->allFiles())->first(fn ($f) => str_ends_with($f, '.html'))
    );

    expect($html)->toContain('Computed, not concluded')
        ->toContain('deep winter')
        ->toContain('true autumn')
        ->toContain('different family');
});

it('says so rather than writing an empty report', function () {
    $this->artisan('color:label-report')
        ->expectsOutputToContain('Nothing has been labelled yet')
        ->assertSuccessful();

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('changes no colour profile and no season', function () {
    /* A report that wrote back would be guessing on exactly the evidence the
     * guessing is meant to stop. */
    labelProfile('deep_winter', 'true_autumn');
    $before = ColorProfile::sole()->only(['season', 'axes', 'confidence']);

    $this->artisan('color:label-report')->assertSuccessful();

    expect(ColorProfile::sole()->only(['season', 'axes', 'confidence']))->toBe($before);
});

<?php

use App\Models\ColorProfile;
use App\Services\Color\MemberColorPresenter;

/*
 * MemberColorPresenter is the wall between the instrument and the member:
 * whatever the engine recorded, the member's shape holds words and swatches
 * only. These pin the shape itself — the keys that exist, the keys that must
 * not, and the wording rules (runner-up only on a close call, Ukrainian
 * regardless of the app locale).
 */

function presented(ColorProfile $profile): array
{
    return app(MemberColorPresenter::class)->present($profile);
}

it('has exactly the member-facing keys, whatever the engine recorded', function () {
    seedPalettes();

    $json = presented(measured());

    expect(array_keys($json))->toBe([
        'id', 'status', 'pending', 'created_at', 'season', 'alternative', 'confidence_level', 'palette', 'products', 'products_filtered_by',
        'reject_message', 'photo', 'photo_hours',
    ]);
});

it('gives the member\'s season its traits and tips, and the runner-up only its name', function () {
    $json = presented(ColorProfile::factory()->classified('soft_summer', 'soft_autumn', 0.2)->create());

    expect(array_keys($json['season']))->toBe(['slug', 'name', 'description', 'traits', 'tips'])
        ->and($json['season']['traits'])->toBe(["М'яке", 'Холодне', 'Приглушене'])
        ->and($json['season']['tips'])->toHaveCount(4)
        ->and($json['season']['tips'][1])->toContain('графіт')
        ->and(array_keys($json['alternative']))->toBe(['slug', 'name', 'description']);
});

it('drops the traits and tips rather than showing a key when a season has no copy', function () {
    $json = presented(measured(['season' => 'new_season', 'seasons' => [['slug' => 'new_season', 'score' => 0.3]]]));

    expect($json['season']['traits'])->toBe([])
        ->and($json['season']['tips'])->toBe([]);
});

it('speaks Ukrainian even while the app locale is English', function () {
    app()->setLocale('en');

    $json = presented(measured(['season' => 'true_summer', 'seasons' => [['slug' => 'true_summer', 'score' => 0.3]]]));

    expect($json['season']['name'])->toBe('Справжнє літо');
});

it('maps the five outcomes to five statuses', function () {
    expect(presented(ColorProfile::factory()->create())['status'])->toBe('pending')
        ->and(presented(ColorProfile::factory()->processing()->create())['status'])->toBe('pending')
        ->and(presented(measured())['status'])->toBe('ok')
        ->and(presented(ColorProfile::factory()->measured()->create())['status'])->toBe('unplaced')
        ->and(presented(ColorProfile::factory()->rejected()->create())['status'])->toBe('rejected')
        ->and(presented(ColorProfile::factory()->failed()->create())['status'])->toBe('failed');
});

it('gives an empty palette rather than an error while nothing is seeded', function () {
    $json = presented(measured());

    expect($json['status'])->toBe('ok')
        ->and($json['palette'])->toBe([]);
});

it('marks only the avoid group as such, and orders the groups to wear first', function () {
    seedPalettes();

    $palette = presented(measured())['palette'];

    expect(array_column($palette, 'avoid'))->toBe([false, false, false, true])
        ->and($palette[0]['label'])->toBe('Нейтральні')
        ->and($palette[3]['label'])->toBe('Менше біля обличчя')
        ->and($palette[3]['hint'])->toContain('гасять його');
});

it('falls back to a readable refusal when the engine names a reason no text covers', function () {
    $json = presented(ColorProfile::factory()->rejected('something_new')->create());

    expect($json['reject_message'])->toBe(__('color_analysis.unsupported_format', [], 'uk'));
});

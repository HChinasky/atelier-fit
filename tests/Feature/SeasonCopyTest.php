<?php

use App\Models\SeasonColor;
use Database\Factories\SeasonFactory;

/*
 * The words the seasons are shown in.
 *
 * Names and descriptions live in lang/ rather than in the seasons table, so
 * "does every locale have every key" is a real question with a real answer, and
 * it is the one this file asks. The keys themselves are a contract with the
 * engine: they are its Season slugs and the palette generator's group names.
 */

$locales = ['en', 'uk', 'ru', 'ar'];

it('names every season the engine can return', function (string $locale) use (&$locales) {
    $names = require lang_path("{$locale}/seasons.php");

    expect(array_keys($names['names']))->toEqualCanonicalizing(SeasonFactory::SLUGS);
    expect(array_keys($names['descriptions']))->toEqualCanonicalizing(SeasonFactory::SLUGS);
})->with($locales);

it('names every palette group the generator writes', function (string $locale) {
    $copy = require lang_path("{$locale}/seasons.php");

    expect(array_keys($copy['groups']))->toEqualCanonicalizing(SeasonColor::GROUPS);
    expect(array_keys($copy['group_hints']))->toEqualCanonicalizing(SeasonColor::GROUPS);
})->with($locales);

it('gives every season three traits and four tips', function (string $locale) {
    $copy = require lang_path("{$locale}/seasons.php");

    expect(array_keys($copy['traits']))->toEqualCanonicalizing(SeasonFactory::SLUGS);
    expect(array_keys($copy['tips']))->toEqualCanonicalizing(SeasonFactory::SLUGS);

    foreach (SeasonFactory::SLUGS as $slug) {
        expect($copy['traits'][$slug])->toHaveCount(3)->each->toBeString()->not->toBeEmpty();
        expect($copy['tips'][$slug])->toHaveCount(4)->each->toBeString()->not->toBeEmpty();
    }
})->with($locales);

it('has the same keys in every locale', function (string $locale) {
    /* A locale missing a key does not error — it renders the key. Comparing
     * against English is the only way that shows up before a user sees it. */
    $english = require lang_path('en/seasons.php');
    $other = require lang_path("{$locale}/seasons.php");

    expect(flattenKeys($other))->toEqualCanonicalizing(flattenKeys($english));
})->with(['uk', 'ru', 'ar']);

it('says the season is a draft, in every locale', function (string $locale) {
    /* The banner is not optional copy. Nothing in the classifier has been
     * checked against a face, and every locale has to say so. */
    $copy = require lang_path("{$locale}/seasons.php");

    expect($copy['draft_banner'])->not->toBeEmpty();
    expect($copy['draft_banner_uncalibrated'])->not->toBeEmpty();
    expect($copy['draft_banner_uncalibrated'])->not->toBe($copy['draft_banner']);
})->with($locales);

it('words the arabic seasons in Arabic, not the English placeholder', function () {
    $copy = require lang_path('ar/seasons.php');

    expect($copy['names']['deep_winter'])->toMatch('/\p{Arabic}/u')
        ->and($copy['draft_banner'])->toMatch('/\p{Arabic}/u');
});

it('ships no locale the site does not have', function () use ($locales) {
    $shipped = array_map('basename', glob(lang_path('*'), GLOB_ONLYDIR));

    expect(array_diff($shipped, $locales))->toBeEmpty();
});

function flattenKeys(array $copy, string $prefix = ''): array
{
    $keys = [];

    foreach ($copy as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
        $keys = is_array($value) && ! array_is_list($value)
            ? [...$keys, ...flattenKeys($value, $path)]
            : [...$keys, $path];
    }

    return $keys;
}

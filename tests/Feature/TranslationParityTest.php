<?php

use App\Services\Locale\Locales;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * Every supported language (config/locales.php) carries the whole interface:
 * the same lang files, the same keys, the same :placeholders, and every key
 * the code asks for. A new language is ready when this file passes for it.
 */

/** @return array<string, string> "file.key.sub" => value, lists included */
function flatTranslations(string $locale): array
{
    $flat = [];
    foreach (File::files(lang_path($locale)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $group = $file->getFilenameWithoutExtension();
        $walk = function (array $items, string $prefix) use (&$walk, &$flat): void {
            foreach ($items as $key => $value) {
                // A null is "no text here" (a quiz question without a hint).
                if ($value === null) {
                    continue;
                }
                is_array($value)
                    ? $walk($value, $prefix.'.'.$key)
                    : $flat[$prefix.'.'.$key] = (string) $value;
            }
        };
        $walk(require $file->getPathname(), $group);
    }
    ksort($flat);

    return $flat;
}

/** @return list<string> the :placeholders in a translation, sorted and unique */
function placeholdersOf(string $text): array
{
    preg_match_all('/:([a-z_]+)/', $text, $m);
    $names = array_values(array_unique($m[1]));
    sort($names);

    return $names;
}

/**
 * Every literal translation key in the code: __('a.b'), trans('a.b'),
 * trans_choice('a.b', …), @lang('a.b'), and the loader steps in config. A
 * prefix built on at run time ('seasons.names.'.$slug) is not a key and is skipped.
 *
 * @return list<string>
 */
function translationKeysInCode(): array
{
    $keys = [];
    $paths = array_merge(File::allFiles(app_path()), File::allFiles(resource_path('views')));
    foreach ($paths as $file) {
        preg_match_all("/(?:__|trans|trans_choice|@lang)\\(\\s*'([a-z_]+\\.[a-z0-9_.]*[a-z0-9_])'/", $file->getContents(), $m);
        array_push($keys, ...$m[1]);
    }
    array_push($keys, ...(array) config('fitting_room.loader.steps'));
    $keys[] = config('fitting_room.loader.background_step');
    $keys[] = config('fitting_room.loader.final_step');

    $keys = array_values(array_unique($keys));
    sort($keys);

    return $keys;
}

it('supports Ukrainian, English, Russian and Arabic', function () {
    expect(Locales::codes())->toBe(['uk', 'en', 'ru', 'ar']);
});

it('has the same lang files in every supported language', function () {
    $files = fn (string $locale) => collect(File::files(lang_path($locale)))
        ->map->getFilename()->sort()->values()->all();

    foreach (Locales::codes() as $locale) {
        expect($files($locale))->toBe($files('en'), "lang/{$locale} differs from lang/en");
    }
});

it('has the same keys in every supported language', function () {
    $english = array_keys(flatTranslations('en'));

    foreach (Locales::codes() as $locale) {
        $keys = array_keys(flatTranslations($locale));
        expect(array_values(array_diff($english, $keys)))->toBe([], "missing in lang/{$locale}")
            ->and(array_values(array_diff($keys, $english)))->toBe([], "only in lang/{$locale}");
    }
});

it('keeps the same placeholders in every translation of a key', function () {
    $english = flatTranslations('en');

    foreach (Locales::codes() as $locale) {
        foreach (flatTranslations($locale) as $key => $text) {
            expect(placeholdersOf($text))->toBe(placeholdersOf($english[$key] ?? ''), "{$locale}: {$key}");
        }
    }
});

it('leaves no translation empty', function () {
    foreach (Locales::codes() as $locale) {
        $empty = array_keys(array_filter(flatTranslations($locale), fn (string $text) => trim($text) === ''));

        expect($empty)->toBe([], "empty in lang/{$locale}");
    }
});

it('has every key the code asks for, in every supported language', function () {
    $keys = translationKeysInCode();
    expect($keys)->not->toBeEmpty();

    foreach (Locales::codes() as $locale) {
        $missing = array_values(array_filter($keys, fn (string $key) => ! Lang::has($key, $locale, false)));

        expect($missing)->toBe([], "keys used in code but missing in lang/{$locale}");
    }
});

it('keeps the photo-retention promise honest in every language', function () {
    // Constraint 3 (CLAUDE.md): "within 72 hours", never "deleted at once".
    expect(__('common.privacy_note', [], 'uk'))->toContain('протягом 72 годин')
        ->and(__('common.privacy_note', [], 'en'))->toContain('within 72 hours')
        ->and(__('common.privacy_note', [], 'ru'))->toContain('в течение 72 часов')
        ->and(__('common.privacy_note', [], 'ar'))->toContain('خلال 72 ساعة');

    foreach (Locales::codes() as $locale) {
        $all = mb_strtolower(implode("\n", flatTranslations($locale)));
        expect($all)->not->toContain('одразу після обробки')
            ->not->toContain('видаляються одразу')
            ->not->toContain('deleted immediately')
            ->not->toContain('deleted right away')
            ->not->toContain('удаляются сразу')
            ->not->toContain('сразу после обработки')
            ->not->toContain('تُحذف فورًا');
    }
});

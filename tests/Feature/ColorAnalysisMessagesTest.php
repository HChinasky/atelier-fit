<?php

use App\Models\ColorProfile;
use App\Models\User;

/*
 * The reject-reason copy.
 *
 * A rejection the reader cannot act on is a rejection nobody can fix, so the
 * tests check two things: that every reason the engine can produce HAS a
 * message in every locale we ship, and that the message says what to do rather
 * than restating the problem.
 */

/**
 * Every RejectReason the engine declares.
 *
 * Mirrors color_engine\models.RejectReason. There is no way to import it across
 * the language boundary, so it is repeated here on purpose: if the engine grows
 * a reason and nobody adds the copy, this list is where it gets noticed.
 */
$reasons = [
    'low_resolution',
    'no_face',
    'multiple_faces',
    'face_too_small',
    'face_not_frontal',
    'unsupported_format',
    'file_too_large',
    'insufficient_skin',
    'low_light',
    'uneven_light',
    'overexposed',
    'color_cast',
    'makeup_filter',
];

/*
 * The locales the site ships (config/locales.php).
 */
$locales = ['en', 'uk', 'ru', 'ar'];

it('has a message for every reason in every locale we ship', function (string $locale) use ($reasons) {
    app()->setLocale($locale);

    foreach ($reasons as $reason) {
        $key = 'color_analysis.'.$reason;
        expect(__($key))->not->toBe($key, "{$locale} is missing {$reason}");
    }
})->with($locales);

it('ships no reason the engine cannot produce', function (string $locale) use ($reasons) {
    /* Copy for a reason that does not exist is copy nobody will ever read, and
     * it makes the file look more complete than it is. */
    $file = lang_path($locale.'/color_analysis.php');
    expect($file)->toBeFile();

    expect(array_keys(require $file))->toEqualCanonicalizing($reasons);
})->with($locales);

it('tells the person what to do, not just what went wrong', function (string $locale) use ($reasons) {
    app()->setLocale($locale);

    foreach ($reasons as $reason) {
        $message = __('color_analysis.'.$reason);

        // Two sentences at least: the observation, then the instruction. Word
        // counting is hopeless across alphabets, so count sentences instead.
        expect(preg_match_all('/[.!?](\s|$)/u', $message))
            ->toBeGreaterThan(1, "{$locale}/{$reason} is too terse to act on");
    }
})->with($locales);

it('ships a lang folder for every supported locale and no other', function () use ($locales) {
    $shipped = array_map('basename', glob(lang_path('*'), GLOB_ONLYDIR));

    expect($shipped)->toEqualCanonicalizing($locales)
        ->and(array_keys((array) config('locales.supported')))->toEqualCanonicalizing($locales);
});

it('words the arabic reasons in Arabic, not the English placeholder', function () {
    $source = file_get_contents(lang_path('ar/color_analysis.php'));

    expect($source)->not->toContain('NEEDS TRANSLATION')
        ->and(__('color_analysis.no_face', [], 'ar'))->toMatch('/\p{Arabic}/u');
});

it('shows the message on the page rather than the slug', function () {
    config(['features.color_analysis' => true]);
    app()->setLocale('en');
    $user = User::factory()->create(['is_admin' => true]);
    $profile = ColorProfile::factory()->for($user)->rejected('low_light')->create();

    $this->actingAs($user)->getJson('/admin/color/'.$profile->id)
        ->assertOk()
        ->assertJsonPath('reject_reason', 'low_light')
        ->assertJsonPath('reject_message', __('color_analysis.low_light'));
});

it('falls back to the raw slug rather than to silence', function () {
    /* A reason the engine grew and we have not written copy for yet must still
     * appear on the page: "rejected, no explanation" is the one outcome that
     * cannot be acted on at all. */
    config(['features.color_analysis' => true]);
    $user = User::factory()->create(['is_admin' => true]);
    $profile = ColorProfile::factory()->for($user)->rejected('something_new')->create();

    $this->actingAs($user)->getJson('/admin/color/'.$profile->id)
        ->assertOk()
        ->assertJsonPath('reject_message', 'something_new');
});

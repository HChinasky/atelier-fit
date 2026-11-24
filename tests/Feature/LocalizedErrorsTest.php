<?php

use App\Models\User;
use App\Services\Outfit\OutfitRejection;
use App\Services\Photo\ValidationFailureReason;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * Everything the back end says to a visitor — validation, the endpoints' own
 * answers, and the errors the framework raises by itself (401, 404, 419, 429,
 * 500, the error pages) — is in the visitor's language. The front-end shows a
 * JSON "message" as it is, so an English or a class name there reaches the page.
 */

beforeEach(function () {
    config(['features.membership' => true, 'features.color_analysis' => true, 'consent.required' => true, 'app.debug' => false]);
    Mail::fake();
    Queue::fake();
    Http::fake();
    Storage::fake('r2');
});

/** Every message and error text in a JSON answer. */
function messagesIn(array $json): array
{
    return array_values(array_filter(
        array_merge([$json['message'] ?? null], array_merge(...array_values(array_map(
            fn ($errors) => (array) $errors,
            (array) ($json['errors'] ?? []),
        )) ?: [[]])),
        'is_string',
    ));
}

/** Latin words a Ukrainian, Russian or Arabic message may carry: formats and names. */
const LATIN_IN_UK_MESSAGES = ['e', 'mail', 'JPG', 'JPEG', 'PNG', 'WebP', 'HEIC', 'Google', 'Apple'];

dataset('bad requests', [
    'code request, empty' => ['guest', 'auth.email.send', [], []],
    'code request, bad address' => ['guest', 'auth.email.send', [], ['email' => 'nope', 'fingerprint' => 'fp']],
    'code check, empty' => ['guest', 'auth.email.verify', [], []],
    'code check, short code' => ['guest', 'auth.email.verify', [], ['email' => 'a@b.test', 'code' => '12']],
    'code check, no such code' => ['guest', 'auth.email.verify', [], ['email' => 'a@b.test', 'code' => '123456']],
    'photo, empty' => ['guest', 'fitting-room.photo.store', [], []],
    'photo, not an image, old wording' => ['guest', 'fitting-room.photo.store', [], ['photo' => 'text', 'consent_accepted' => '1', 'consent_version' => 'old', 'terms_accepted' => '1', 'terms_version' => 'old']],
    'photo, too large' => ['guest', 'fitting-room.photo.store', [], ['photo' => 'big']],
    'try-on, empty' => ['guest', 'fitting-room.generate', [], []],
    'try-on, nonsense' => ['guest', 'fitting-room.generate', [], ['lead_id' => 'x', 'product_id' => 'x', 'photo_key' => 'x', 'consent_id' => 'x']],
    'outfit, empty' => ['guest', 'fitting-room.generate-outfit', [], []],
    'outfit, nonsense' => ['guest', 'fitting-room.generate-outfit', [], ['product_ids' => [1, 1], 'lead_id' => 'x', 'consent_id' => 'x']],
    'avatar, signed out' => ['guest', 'membership.account.avatar.update', [], []],
    'avatar, empty' => ['member', 'membership.account.avatar.update', [], []],
    'avatar, not an image' => ['member', 'membership.account.avatar.update', [], ['avatar' => 'text']],
    'account photo, empty' => ['member', 'membership.account.try-on.photo.store', [], []],
    'account photo, not an image' => ['member', 'membership.account.try-on.photo.store', [], ['photo' => 'text']],
    'style quiz, bad answer' => ['member', 'membership.account.onboarding.store', [], ['metal' => 'nope', 'photo' => 'text']],
    'colour photo, empty' => ['member', 'membership.account.color.store', [], []],
    'favourite, no such piece' => ['member', 'membership.account.favorites.store', ['product' => 999999], []],
    'wardrobe, no such look' => ['member', 'membership.account.wardrobe.looks.hide', ['generation' => 999999], []],
]);

it('answers a bad request in the visitor language', function (string $who, string $route, array $parameters, array $data) {
    $files = [
        'text' => fn () => UploadedFile::fake()->create('notes.txt', 3, 'text/plain'),
        'big' => fn () => UploadedFile::fake()->image('big.jpg')->size(30000),
    ];
    $data = array_map(fn ($value) => is_string($value) && isset($files[$value]) ? $files[$value]() : $value, $data);
    $method = str_ends_with($route, '.hide') ? 'delete' : 'post';
    $member = User::factory()->emailOnly()->onboarded()->create();

    foreach (['uk', 'en', 'ru', 'ar'] as $locale) {
        if ($who === 'member') {
            $this->actingAs($member);
        }

        $response = $this->withHeader('Accept-Language', $locale)->json($method, route($route, $parameters), $data);
        $messages = messagesIn($response->json() ?? []);

        expect($response->status())->toBeGreaterThanOrEqual(400)
            ->and($messages)->not->toBeEmpty();

        foreach ($messages as $message) {
            // No model or class name, no raw field name like "consent_version" or "lead id".
            expect($message)->not->toContain('App\\')
                ->not->toMatch('/\b[a-z]+_[a-z]+\b/')
                ->not->toMatch('/\b(lead|consent|terms) (id|version)\b/');

            if ($locale !== 'en') {
                preg_match_all('/[A-Za-z]+/', $message, $latin);
                expect(array_values(array_diff($latin[0], LATIN_IN_UK_MESSAGES)))->toBe([], "{$locale}: {$message}");
            }
            if (in_array($locale, ['en', 'ar'], true)) {
                expect($message)->not->toMatch('/[\x{0400}-\x{04FF}]/u');
            }
            if ($locale === 'ar') {
                expect($message)->toMatch('/[\x{0600}-\x{06FF}]/u');
            }
        }
    }
})->with('bad requests');

it('says how many more errors in Ukrainian plural forms', function (int $count, string $tail) {
    $validator = validator(
        array_fill_keys(range(0, $count), ''),
        array_fill_keys(range(0, $count), 'required'),
    );

    app()->setLocale('uk');

    expect((new ValidationException($validator))->getMessage())->toEndWith($tail);
})->with([
    [1, '(і ще 1 помилка)'],
    [3, '(і ще 3 помилки)'],
    [5, '(і ще 5 помилок)'],
    [21, '(і ще 21 помилка)'],
]);

it('says how many more errors in Russian and Arabic plural forms', function (string $locale, int $count, string $tail) {
    $validator = validator(
        array_fill_keys(range(0, $count), ''),
        array_fill_keys(range(0, $count), 'required'),
    );

    app()->setLocale($locale);

    expect((new ValidationException($validator))->getMessage())->toEndWith($tail);
})->with([
    ['ru', 1, '(и ещё 1 ошибка)'],
    ['ru', 3, '(и ещё 3 ошибки)'],
    ['ru', 5, '(и ещё 5 ошибок)'],
    ['ar', 1, '(وخطأ آخر)'],
    ['ar', 2, '(وخطآن آخران)'],
    ['ar', 5, '(و5 أخطاء أخرى)'],
    ['ar', 11, '(و11 خطأً آخر)'],
]);

it('words the framework errors itself in the visitor language', function (string $locale, string $expired, string $throttled, string $broken) {
    Route::middleware('web')->post('/_test/expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
    Route::middleware(['web', 'throttle:1,1'])->post('/_test/throttled', fn () => 'ok');
    Route::middleware('web')->post('/_test/broken', fn () => throw new RuntimeException('database exploded at App\\Models\\Lead'));

    $headers = ['Accept-Language' => $locale];

    $this->postJson('/_test/expired', [], $headers)->assertStatus(419)->assertExactJson(['message' => $expired]);

    $this->postJson('/_test/throttled', [], $headers)->assertOk();
    $this->postJson('/_test/throttled', [], $headers)->assertStatus(429)
        ->assertJsonPath('message', $throttled)
        ->assertHeader('Retry-After');

    $this->postJson('/_test/broken', [], $headers)->assertStatus(500)->assertExactJson(['message' => $broken]);
})->with([
    ['uk', 'Сторінка застаріла. Онови її й спробуй ще раз.', 'Забагато спроб. Зачекай хвилинку й спробуй ще раз.', 'Щось пішло не так. Спробуй ще раз трохи згодом.'],
    ['en', 'This page has expired. Refresh it and try again.', 'Too many attempts. Please wait a minute and try again.', 'Something went wrong. Please try again a little later.'],
    ['ru', 'Страница устарела. Обнови её и попробуй ещё раз.', 'Слишком много попыток. Подожди минутку и попробуй ещё раз.', 'Что-то пошло не так. Попробуй ещё раз чуть позже.'],
    ['ar', 'انتهت صلاحية الصفحة. حدّثها وحاول مجددًا.', 'محاولات كثيرة جدًا. يُرجى الانتظار دقيقة والمحاولة مجددًا.', 'حدث خطأ ما. يُرجى المحاولة مجددًا بعد قليل.'],
]);

it('picks the language for an address no route knows', function () {
    $this->withHeader('Accept-Language', 'uk')->getJson('/api/no-such-thing')
        ->assertNotFound()->assertExactJson(['message' => 'Не знайдено.']);

    $this->withHeader('Accept-Language', 'en')->getJson('/api/no-such-thing')
        ->assertNotFound()->assertExactJson(['message' => 'Not found.']);
});

it('draws the error page in the visitor language and the site palette', function () {
    $this->withHeader('Accept-Language', 'uk')->get('/no-such-page')
        ->assertNotFound()
        ->assertSee('<html lang="uk"', escape: false)
        ->assertSee('Сторінку не знайдено')
        ->assertSee('На головну')
        ->assertSee('id="error-page"', escape: false);

    $this->withHeader('Accept-Language', 'en')->get('/no-such-page')
        ->assertNotFound()
        ->assertSee('<html lang="en"', escape: false)
        ->assertSee('Not Found')
        ->assertSee('Back to the start');

    $this->withHeader('Accept-Language', 'ru')->get('/no-such-page')
        ->assertNotFound()
        ->assertSee('<html lang="ru"', escape: false)
        ->assertSee('Страница не найдена');

    $this->withHeader('Accept-Language', 'ar')->get('/no-such-page')
        ->assertNotFound()
        ->assertSee('<html lang="ar" dir="rtl"', escape: false)
        ->assertSee('الصفحة غير موجودة')
        ->assertSee('العودة إلى البداية');
});

it('keeps a debug trace in debug mode', function () {
    config(['app.debug' => true]);
    Route::middleware('web')->post('/_test/broken', fn () => throw new RuntimeException('the real reason'));

    $this->postJson('/_test/broken')->assertStatus(500)->assertJsonPath('message', 'the real reason');
});

it('has a message in every language for each photo and outfit refusal', function (string $enum) {
    foreach ($enum::cases() as $case) {
        foreach (array_keys((array) config('locales.supported')) as $locale) {
            expect(Lang::has($case->messageKey(), $locale, false))->toBeTrue("{$case->messageKey()} [{$locale}]");
        }
    }
})->with([
    ValidationFailureReason::class,
    OutfitRejection::class,
]);

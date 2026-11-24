<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * POST /api/fitting-room/photo — stores a client photo on the private R2 disk
 * ahead of generation. R2 is faked (no real Cloudflare calls / private data).
 */

beforeEach(function () {
    Storage::fake('r2');
});

/**
 * The consent fields every upload must carry. Consent is enforced server-side, so
 * a request without them is refused whatever the browser did — that gate has its
 * own suite (PhotoConsentTest); here it is simply satisfied.
 *
 * @return array<string, string>
 */
function withConsent(array $payload): array
{
    return $payload + [
        'consent_accepted' => '1',
        'consent_version' => (string) config('consent.version'),
        'terms_accepted' => '1',
        'terms_version' => (string) config('terms.version'),
    ];
}

it('accepts a valid image, stores it on r2 under an unguessable key, and returns the key', function () {
    $response = $this->postJson('/api/fitting-room/photo', withConsent([
        'photo' => UploadedFile::fake()->image('selfie.jpg', 800, 1000),
    ]));

    $response->assertCreated()
        ->assertJsonStructure(['photo_key', 'expires_at']);

    $key = $response->json('photo_key');

    Storage::disk('r2')->assertExists($key);

    // Unguessable: a uuid filename, not the original name.
    expect($key)->toMatch('#^uploads/\d{4}-\d{2}-\d{2}/[0-9a-f-]{36}\.(jpe?g|png|webp)$#')
        ->and($key)->not->toContain('selfie');
});

it('stores the photo under the private uploads prefix (not a public zone)', function () {
    $key = $this->postJson('/api/fitting-room/photo', withConsent([
        'photo' => UploadedFile::fake()->image('selfie.png'),
    ]))->json('photo_key');

    expect($key)->toStartWith('uploads/');
    expect(Storage::disk('r2')->getVisibility($key))->toBe('private');
});

it('returns an internal key, never a public URL', function () {
    $response = $this->postJson('/api/fitting-room/photo', withConsent([
        'photo' => UploadedFile::fake()->image('selfie.webp'),
    ]));

    $key = $response->json('photo_key');
    expect($key)->not->toStartWith('http')
        // The response carries no public/direct URL field.
        ->and($response->json())->not->toHaveKey('url')
        ->and($response->json())->not->toHaveKey('public_url');
});

it('rejects a non-image file and stores nothing', function () {
    $response = $this->postJson('/api/fitting-room/photo', withConsent([
        'photo' => UploadedFile::fake()->create('document.pdf', 200, 'application/pdf'),
    ]));

    $response->assertStatus(422);
    expect(Storage::disk('r2')->allFiles())->toBeEmpty();
});

it('rejects a text file masquerading as a jpg (spoofed type) and stores nothing', function () {
    // A real UploadedFile so the mimetypes rule runs finfo on the actual bytes:
    // the content is text, the name claims .jpg / image/jpeg — must be rejected.
    $path = tempnam(sys_get_temp_dir(), 'spoof');
    file_put_contents($path, 'this is plain text, not an image');
    $spoof = new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);

    $response = $this->postJson('/api/fitting-room/photo', withConsent(['photo' => $spoof]));

    $response->assertStatus(422);
    expect(Storage::disk('r2')->allFiles())->toBeEmpty();
});

it('rejects an oversized image and stores nothing', function () {
    $response = $this->postJson('/api/fitting-room/photo', withConsent([
        // 20 MB > 12 MB cap.
        'photo' => UploadedFile::fake()->image('huge.jpg')->size(20000),
    ]));

    $response->assertStatus(422);
    expect(Storage::disk('r2')->allFiles())->toBeEmpty();
});

it('requires a photo', function () {
    $this->postJson('/api/fitting-room/photo', [])->assertStatus(422);
});

it('rate limits uploads after the allowed number per minute', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/fitting-room/photo', withConsent([
            'photo' => UploadedFile::fake()->image("ok{$i}.jpg"),
        ]))->assertCreated();
    }

    $this->postJson('/api/fitting-room/photo', withConsent([
        'photo' => UploadedFile::fake()->image('over.jpg'),
    ]))->assertStatus(429);
});

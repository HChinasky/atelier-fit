<?php

namespace App\Services\Photo;

use App\Http\Requests\StorePhotoRequest;
use App\Models\PhotoConsent;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Support\Str;

/**
 * Parks a client photo in private R2 ahead of generation. No FASHN call
 * happens here — this only stores the photo and hands back the internal key
 * the generation step resolves. Shared by the try-on API and the account's
 * "Примірка" step 1.
 *
 * Privacy: the photo is private (never public), stored under a single
 * date-partitioned prefix so the cleanup sweep / R2 lifecycle can find and
 * delete it. We never log the photo's contents or its original filename.
 */
class ClientPhotoStore
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    /**
     * @return array{photo_key: string, consent_id: int|null, expires_at: string}
     */
    public function store(StorePhotoRequest $request): array
    {
        $disk = config('fitting_room.photo.disk');
        $prefix = config('fitting_room.photo.prefix');
        $ttlHours = (int) config('fitting_room.photo.ttl_hours');

        // Record the consent BEFORE the photo is stored: the record is what makes
        // the consent demonstrable, and it has to exist for anything the photo is
        // then used for. It deliberately outlives the photo (72h) — see the
        // consent retention period in config/privacy.php.
        // With consent switched off nothing was agreed, so nothing is recorded.
        $consent = config('consent.required') ? PhotoConsent::create([
            'consent_version' => (string) $request->validated('consent_version'),
            'terms_version' => (string) $request->validated('terms_version'),
            'accepted_at' => now(),
            'terms_accepted_at' => now(),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]) : null;

        $file = $request->file('photo');

        // Unguessable name; extension derived from the real MIME, NOT the
        // user-supplied filename. Date partition keeps photos groupable by age
        // for lifecycle cleanup.
        $directory = sprintf('%s/%s', $prefix, now()->format('Y-m-d'));
        $filename = Str::uuid().'.'.$file->extension();

        $key = $file->storeAs($directory, $filename, ['disk' => $disk, 'visibility' => 'private']);

        // Numbers only — never the photo, its key or its filename.
        [$width, $height] = $this->dimensions($file->getRealPath());
        $this->analytics->capture('photo_uploaded', array_filter([
            'size_kb' => (int) round($file->getSize() / 1024),
            'width' => $width,
            'height' => $height,
        ], fn ($v) => $v !== null));

        return [
            'photo_key' => $key,
            'consent_id' => $consent?->id,
            'expires_at' => now()->addHours($ttlHours)->toIso8601String(),
        ];
    }

    /**
     * Pixel dimensions of the uploaded image, best-effort (a corrupt file must
     * not fail the upload over an analytics nicety).
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(string|false $path): array
    {
        if ($path === false) {
            return [null, null];
        }

        $info = @getimagesize($path);

        return is_array($info) ? [(int) $info[0], (int) $info[1]] : [null, null];
    }
}

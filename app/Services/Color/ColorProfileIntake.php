<?php

namespace App\Services\Color;

use App\Jobs\AnalyzeColorProfile;
use App\Models\ColorProfile;
use App\Models\ColorProfileImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Takes a face photo in and starts the analysis: the profile row, the photo
 * in private storage on the purge clock, and the job on the colour queue.
 *
 * One place for it, because two surfaces do exactly this — the internal
 * instrument at /admin/color and the member's own page at /account/color —
 * and they must not drift apart on where a face is stored or for how long.
 * What differs between them is passed in: what the row records (quiz,
 * calibration handle, consent) and whether the engine is asked for its debug
 * pictures, which the instrument shows and a member never sees.
 *
 * Nothing here logs a photo, a key or a filename.
 */
class ColorProfileIntake
{
    /**
     * @param  array<string, mixed>  $attributes  extra columns for the profile row
     *                                            (quiz, person_ref, lighting, consented_at)
     */
    public function start(User $user, UploadedFile $photo, array $attributes = [], bool $debug = false): ColorProfile
    {
        $profile = ColorProfile::create($attributes + [
            'user_id' => $user->id,
            'status' => ColorProfile::STATUS_PENDING,
            'source' => 'auto',
        ]);

        // Unguessable name, extension from the real MIME rather than whatever
        // the upload claimed, date partition so the objects stay groupable by
        // age — the same shape as the try-on upload, under the same prefix,
        // which is what puts it on the existing photo purge.
        $directory = sprintf(
            '%s/%s/%s/%s',
            (string) config('fitting_room.photo.prefix'),
            (string) config('color_analysis.storage.folder'),
            now()->format('Y-m-d'),
            Str::uuid(),
        );
        $disk = (string) config('fitting_room.photo.disk');
        $path = $photo->storeAs(
            $directory,
            'source.'.$photo->extension(),
            ['disk' => $disk, 'visibility' => 'private'],
        );

        ColorProfileImage::create([
            'color_profile_id' => $profile->id,
            'kind' => ColorProfileImage::KIND_SOURCE,
            'disk' => $disk,
            'path' => $path,
            'expires_at' => now()->addHours((int) config('privacy.photo_hard_delete_hours')),
        ]);

        AnalyzeColorProfile::dispatch($profile, $debug)
            ->onConnection((string) config('color_analysis.queue_connection'))
            ->onQueue((string) config('color_analysis.queue'));

        return $profile->refresh();
    }
}

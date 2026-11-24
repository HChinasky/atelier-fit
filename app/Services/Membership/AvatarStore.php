<?php

namespace App\Services\Membership;

use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DriverInterface;
use Throwable;

/**
 * A member's profile picture: stored privately, one per account.
 *
 * The upload is re-encoded to a square JPEG — which also strips EXIF, GPS
 * included — and written under its own prefix with a fresh key, so a cached
 * copy of the old picture can never be served for the new one. The previous
 * object is removed once the new one is in place.
 */
class AvatarStore
{
    public function replace(User $user, UploadedFile $file): void
    {
        $size = (int) config('fitting_room.avatar.size', 512);
        $image = (new ImageManager($this->driver()))->read($file->getRealPath());
        $contents = (string) $image->orient()->cover($size, $size)->toJpeg(quality: 88);

        $key = trim((string) config('fitting_room.avatar.prefix'), '/').'/'.$user->id.'/'.Str::uuid().'.jpg';
        $this->disk()->put($key, $contents, ['visibility' => 'private', 'ContentType' => 'image/jpeg']);

        $previous = $user->avatar_path;
        $user->forceFill(['avatar_path' => $key])->save();

        $this->deleteObject($previous);
    }

    public function remove(User $user): void
    {
        $previous = $user->avatar_path;
        $user->forceFill(['avatar_path' => null])->save();

        $this->deleteObject($previous);
    }

    /** Erasure path: the object only, the row is about to go. */
    public function deleteObject(?string $key): bool
    {
        if ($key === null || $key === '') {
            return true;
        }

        try {
            return $this->disk()->delete($key);
        } catch (Throwable) {
            return false;
        }
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('fitting_room.avatar.disk'));
    }

    private function driver(): DriverInterface
    {
        return match (strtolower((string) config('photo.normalization.driver', 'gd'))) {
            'imagick' => new ImagickDriver,
            default => new GdDriver,
        };
    }
}

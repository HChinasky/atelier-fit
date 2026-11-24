<?php

namespace App\Http\Controllers\Membership;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAvatarRequest;
use App\Services\Membership\AvatarStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Intervention\Image\Exceptions\DecoderException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The member's profile picture: shown, replaced or removed from the profile.
 *
 * Served through this route rather than a bucket URL: the bucket is private
 * and the picture is only ever the signed-in member's own.
 */
class AvatarController extends Controller
{
    public function show(Request $request, AvatarStore $avatars): StreamedResponse
    {
        $key = $request->user()->avatar_path;
        abort_unless(is_string($key) && $key !== '' && $avatars->disk()->exists($key), 404);

        return $avatars->disk()->response($key, 'avatar.jpg', [
            'Content-Type' => 'image/jpeg',
            // The key changes with every upload and the URL carries it as
            // ?v=, so the browser may keep this one — but only for itself.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function update(UpdateAvatarRequest $request, AvatarStore $avatars): RedirectResponse
    {
        try {
            $avatars->replace($request->user(), $request->file('avatar'));
        } catch (DecoderException) {
            // Passed the MIME check but cannot be read as an image (truncated,
            // corrupt): the member's mistake, not a server error.
            return back()->withErrors(['avatar' => __('requests.avatar.unreadable')]);
        }

        return back()->with('avatar_status', __('requests.avatar.updated'));
    }

    public function destroy(Request $request, AvatarStore $avatars): RedirectResponse
    {
        $avatars->remove($request->user());

        return back()->with('avatar_status', __('requests.avatar.removed'));
    }
}

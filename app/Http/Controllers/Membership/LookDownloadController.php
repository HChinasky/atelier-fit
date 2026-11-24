<?php

namespace App\Http\Controllers\Membership;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /account/looks/{generation}/download — "Зберегти образ": the finished
 * look as a file on the member's device.
 *
 * Served from our own storage rather than linked: the result lives on another
 * origin, where a download attribute is ignored. Only the member whose lead
 * made the look gets it; anybody else, an unfinished look, or one whose image
 * has already expired with the photo lifecycle is a plain 404.
 */
class LookDownloadController extends Controller
{
    public function __invoke(Request $request, Generation $generation): StreamedResponse
    {
        abort_unless(
            $generation->status === 'done' && $generation->lead?->user_id === $request->user()->id,
            404,
        );

        $key = $generation->result_keys[0] ?? null;
        $disk = Storage::disk((string) config('fitting_room.photo.disk'));
        abort_unless(is_string($key) && $key !== '' && $disk->exists($key), 404);

        $extension = pathinfo($key, PATHINFO_EXTENSION) ?: 'png';

        return $disk->download($key, "atelier-fit-look-{$generation->id}.{$extension}", [
            // An image of a person behind a session check: never cached where a
            // cache would serve it without one.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}

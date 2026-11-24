<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePhotoRequest;
use App\Services\Photo\ClientPhotoStore;
use Illuminate\Http\JsonResponse;

/**
 * Uploads a client photo to private R2 storage ahead of generation (Phase 4).
 * No FASHN call happens here — see ClientPhotoStore.
 */
class PhotoUploadController extends Controller
{
    /**
     * POST /api/fitting-room/photo
     */
    public function store(StorePhotoRequest $request, ClientPhotoStore $photos): JsonResponse
    {
        // photo_key: internal storage key (private bucket) — NOT a public URL.
        // consent_id: proof of consent, carried on to the generation step so that
        // call can be refused too if it ever arrives without one.
        // expires_at: honest TTL hint, in lockstep with the on-page 72-hour promise.
        return response()->json($photos->store($request), 201);
    }
}

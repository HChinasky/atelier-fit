<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GenerationStatusResource;
use App\Models\Generation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only status of a single generation, polled by the front-end loader until
 * the generation reaches done/failed.
 *
 * Access control (privacy): result_urls are AI-generated images of a person, so
 * a bare "GET by id for everyone" would let anyone enumerate ids and pull other
 * people's results. The route is protected by a SIGNED URL (the `signed`
 * middleware): POST /generate hands back a temporary signed status URL whose
 * HMAC (keyed by APP_KEY) covers this exact id. A different/forged id has no
 * valid signature, so the middleware rejects it with a 403 before this method
 * runs. Chosen over a session/lead binding because the API is stateless (no
 * session) and this needs no extra column or token table.
 */
class GenerationStatusController extends Controller
{
    /**
     * GET /api/fitting-room/generation/{generation}  (signed)
     */
    public function show(Request $request, Generation $generation): JsonResponse
    {
        // Resolve to a flat payload (no "data" wrapper) so the loader reads
        // body.status / body.result_urls directly. The resource's when() guards
        // are filtered out here, so absent fields never appear.
        return response()->json(
            GenerationStatusResource::make($generation)->resolve($request)
        );
    }
}

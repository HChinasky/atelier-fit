<?php

use App\Http\Controllers\Api\AnalyticsEventController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\GenerationController;
use App\Http\Controllers\Api\GenerationStatusController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\OutfitGenerationController;
use App\Http\Controllers\Api\PhotoUploadController;
use Illuminate\Support\Facades\Route;

/*
 * Try-on API. Routes here are automatically prefixed with /api and use the
 * stateless "api" middleware group (no Sanctum — these endpoints carry their
 * own gates: rate limits, the lead gate, and a signed status URL).
 *
 * Everything that STARTS work lives behind FEATURE_FITTING_ROOM, exactly like
 * the page it serves: with the flag off the whole try-on surface is a plain
 * 404 and no FASHN call can be initiated. The signed status route stays out of
 * the group so a generation queued before the flag flipped can still be polled
 * to its end — its HMAC is the gate there.
 */

Route::middleware('feature:fitting_room')->group(function () {
    // Catalog browsed by the front-end. Returns only try-on-ready (verified)
    // products in production; see CatalogController for the dev override.
    Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');

    // Lead capture. Throttled (named "lead" limiter) as a guard against form spam.
    Route::post('/lead', [LeadController::class, 'store'])
        ->middleware('throttle:lead')
        ->name('lead.store');

    // First-party sink for browser-only analytics events (taps the server
    // cannot see itself). Strictly allow-listed; throttled as plain flood
    // hygiene. No vendor script is ever loaded for this.
    Route::post('/analytics/event', [AnalyticsEventController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('analytics.event');

});

// The endpoints a try-on is MADE of — photo in, generation queued — serve two
// surfaces: the internal page above and the member's account dashboard. Either
// flag opens them; closing the internal page (FEATURE_FITTING_ROOM) must not
// close the account's try-on with it. The lead gate is what keeps a guest out
// while only membership is on: without /api/lead there is no lead to generate
// with, and a member's lead carries its HMAC token.
Route::middleware('feature:fitting_room|membership')->group(function () {
    // Client photo upload to private R2. Throttled to keep junk out of object storage.
    Route::post('/fitting-room/photo', [PhotoUploadController::class, 'store'])
        ->middleware('throttle:photo-upload')
        ->name('fitting-room.photo.store');

    // Queue a try-on generation. Budget gates (lead, global cap, per-user) run in
    // the controller before any FASHN call — see GenerationGate.
    Route::post('/fitting-room/generate', [GenerationController::class, 'store'])
        ->name('fitting-room.generate');

    // Queue an OUTFIT (multi-garment) try-on: a chain of single calls on one person.
    // Same gates as /generate, plus set-composition validation; the set counts as N
    // generations against the budget. Polled via the same signed status route below.
    Route::post('/fitting-room/generate-outfit', [OutfitGenerationController::class, 'store'])
        ->name('fitting-room.generate-outfit');
});

// Poll a generation's status. SIGNED: the only valid way in is the temporary
// signed URL handed back by /generate, whose HMAC covers this exact id — so
// nobody can enumerate ids to read someone else's generated images.
Route::get('/fitting-room/generation/{generation}', [GenerationStatusController::class, 'show'])
    ->middleware('signed')
    ->name('fitting-room.generation.status');

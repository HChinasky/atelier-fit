<?php

/*
 * The try-on surface is INTERNAL: /ai-fitting-room is a testing page for the
 * pipeline, not a client-facing one. It ships closed behind
 * FEATURE_FITTING_ROOM — flag off means a plain 404 for the page AND for every
 * endpoint that could start work (catalog, lead, photo, generate), so nothing
 * can reach FASHN while it is closed. The suite runs with the flag ON (see
 * phpunit.xml); these tests flip it off and pin the closed state.
 */

use App\Models\Generation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/** Every route that opens the try-on funnel: [method, uri]. */
$entryPoints = [
    'page' => ['get', '/ai-fitting-room'],
    'catalog' => ['get', '/api/catalog'],
    'lead' => ['post', '/api/lead'],
    'photo' => ['post', '/api/fitting-room/photo'],
    'generate' => ['post', '/api/fitting-room/generate'],
    'outfit' => ['post', '/api/fitting-room/generate-outfit'],
];

it('ships closed in the operator baseline', function () {
    // The suite turns the flag ON (phpunit.xml) because it is what exercises
    // the pipeline — so the "closed by default" promise is pinned where it
    // actually matters: the .env.example an operator copies on deploy.
    expect(file_get_contents(base_path('.env.example')))
        ->toContain('FEATURE_FITTING_ROOM=false');
});

it('404s the page and every try-on endpoint while the flag is off', function (string $method, string $uri) {
    config(['features.fitting_room' => false]);

    $this->{$method}($uri, [])->assertNotFound();
})->with($entryPoints);

it('keeps the photo and generate endpoints open for members while the internal page is closed', function () {
    // The endpoints a try-on is made of serve the account too: with only
    // membership on they answer (here: validation), while the page, the
    // catalog and the guest lead capture stay closed. A guest without a lead
    // still cannot generate — the lead gate is the guard, not the flag.
    config(['features.fitting_room' => false, 'features.membership' => true]);

    $this->postJson('/api/fitting-room/photo', [])->assertUnprocessable();
    $this->postJson('/api/fitting-room/generate', [])->assertUnprocessable();
    $this->postJson('/api/fitting-room/generate-outfit', [])->assertUnprocessable();

    $this->get('/ai-fitting-room')->assertNotFound();
    $this->getJson('/api/catalog')->assertNotFound();
    $this->postJson('/api/lead', [])->assertNotFound();
});

it('serves the page and the catalog while the flag is on', function () {
    config(['features.fitting_room' => true]);
    Product::factory()->verified()->create();

    $this->get('/ai-fitting-room')->assertOk()->assertViewIs('ai-fitting-room');
    $this->getJson('/api/catalog')->assertOk();
});

it('still lets an already-queued generation be polled after the flag closes', function () {
    // The signed status route stays outside the flag group on purpose: a run
    // started while the surface was open must be able to finish.
    config(['features.fitting_room' => false]);

    $generation = Generation::factory()->processing()->create();
    $url = URL::temporarySignedRoute(
        'fitting-room.generation.status',
        now()->addMinutes(30),
        ['generation' => $generation->id],
    );

    $this->getJson($url)->assertOk()->assertJsonPath('status', 'processing');
});

it('is not linked from any client-facing page', function () {
    config(['features.membership' => true, 'features.fitting_room' => true]);

    $this->get('/')->assertOk()->assertDontSee('/ai-fitting-room');

    $this->actingAs(User::factory()->emailOnly()->onboarded()->create())
        ->get('/account')
        ->assertOk()
        ->assertDontSee('/ai-fitting-room');
});

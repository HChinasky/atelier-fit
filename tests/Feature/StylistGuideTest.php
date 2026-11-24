<?php

use App\Models\ColorProfileLabel;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
 * The guide, and the anchoring it exists to prevent.
 *
 * A stylist who reads "deep winter" before deciding will agree with it more
 * often than they otherwise would — not through carelessness, but because that
 * is what anchoring does to everybody. The agreement rate is the single number
 * this whole exercise measures, so an inflated one makes the report say the
 * engine is doing better than it is.
 */

beforeEach(function () {
    Storage::fake('r2');
    config(['features.color_analysis' => true, 'privacy.photo_hard_delete_hours' => 60]);
});

it('serves the guide to a stylist', function () {
    $this->actingAs(stylist())->get('/stylist/color/guide')
        ->assertOk()
        ->assertSee('Judge the face', false);
});

it('is a 404 for an ordinary member', function () {
    $this->actingAs(User::factory()->create())->get('/stylist/color/guide')->assertNotFound();
});

it('renders the document in the repository rather than a second copy', function () {
    /* One copy of the text. A page and a document that drift apart are worse
     * than either alone. */
    $markdown = file_get_contents(base_path('docs/stylist-colour-labelling.md'));

    expect($markdown)->toContain('Judge the face. Do not judge the engine.');

    $this->actingAs(stylist())->get('/stylist/color/guide')
        ->assertOk()
        ->assertSee('Leave one blank rather than guess', false);
});

it('tells a stylist what to do when they are unsure', function () {
    /* low plus a second season: hedging is information, not indecision. */
    $this->actingAs(stylist())->get('/stylist/color/guide')
        ->assertOk()
        ->assertSee('Second season', false)
        ->assertSee('hedging is information', false);
});

// --- the anchoring guard -------------------------------------------------

it('keeps the engine season behind a click until a verdict is saved', function () {
    $profile = measured(['season' => 'deep_winter']);

    $response = $this->actingAs(stylist())->get("/stylist/color/{$profile->id}")->assertOk();

    // Behind a deliberate act, and warned about.
    $response->assertSee('Show what the engine said', false);
    $response->assertSee('only if you have already decided', false);

    /* And the season sits INSIDE the collapsed element, not beside it. A
     * <details> does keep its contents in the source — this is a guard against
     * anchoring a person, not against reading page source, and somebody
     * inspecting the DOM to find the answer has already decided to. What must
     * not happen is the answer being visible without asking for it. */
    $html = $response->content();
    $details = substr($html, strpos($html, '<details'));
    $details = substr($details, 0, strpos($details, '</details>'));

    expect($details)->toContain('<b>Deep winter</b>');
    /* Outside it, the only Deep winter on the page is an <option> in the season
     * dropdowns — every profile lists all twelve, which says nothing about this
     * one. It is the engine's own bolded verdict that must not be loose. */
    expect(str_replace($details, '', $html))->not->toContain('<b>Deep winter</b>');
});

it('shows the engine season once a verdict is on record', function () {
    $user = stylist();
    $profile = measured(['season' => 'deep_winter']);
    ColorProfileLabel::factory()->create([
        'color_profile_id' => $profile->id,
        'user_id' => $user->id,
        'season' => 'true_autumn',
    ]);

    $this->actingAs($user)->get("/stylist/color/{$profile->id}")
        ->assertOk()
        ->assertSee('Deep winter', false)
        ->assertDontSee('Show what the engine said', false);
});

it('warns on the labelling screen about judging the engine', function () {
    $profile = measured();

    $this->actingAs(stylist())->get("/stylist/color/{$profile->id}")
        ->assertOk()
        ->assertSee('Judge the face, not the engine', false);
});

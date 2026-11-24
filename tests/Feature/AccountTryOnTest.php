<?php

/*
 * /account/try-on was the "Примірка" screen on a page of its
 * own; the screen is the phone's dashboard now, so the address leads there.
 */

use App\Models\User;

beforeEach(function () {
    config(['features.membership' => true]);
});

it('is for signed-in members only', function () {
    $this->get('/account/try-on')->assertRedirect(route('login'));
});

it('is closed with the membership flag', function () {
    config(['features.membership' => false]);

    $this->actingAs(User::factory()->emailOnly()->onboarded()->create())->get('/account/try-on')->assertNotFound();
});

it('leads to the dashboard, where the phone has the screen', function () {
    $this->actingAs(User::factory()->emailOnly()->onboarded()->create())
        ->get('/account/try-on')
        ->assertRedirect(route('membership.account.dashboard'));

    expect(file_exists(resource_path('views/membership/tryon.blade.php')))->toBeFalse()
        ->and(file_exists(lang_path('uk/tryon.php')))->toBeFalse();
});

it('shows the transparent model picture, and only that one', function () {
    // The transparent PNG is the only hero picture; the old photo is gone.
    expect(public_path('images/tryon/hero-model.png'))->toBeFile()
        ->and(file_exists(public_path('images/tryon/hero-model.jpg')))->toBeFalse();
});

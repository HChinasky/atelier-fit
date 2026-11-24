<?php

it('serves the privacy policy as a public HTML page without the internal draft banner', function () {
    $this->get('/legal/privacy-policy')
        ->assertOk()
        ->assertSee('Privacy Policy')
        ->assertDontSee('ЧЕРНОВИК');
});

it('404s for unknown documents', function () {
    $this->get('/legal/nope')->assertNotFound();
});

it('links the landing page to the HTML policy and states the purpose', function () {
    config(['features.membership' => true]);

    $this->get('/')
        ->assertOk()
        ->assertSee(route('legal.show', 'privacy-policy'), false)
        ->assertSee(__('login.page.purpose'));
});

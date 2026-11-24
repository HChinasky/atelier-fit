<?php

it('tells search engines not to index pages', function () {
    $this->get('/pricing')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('sends the noindex header on 404s and API responses too', function () {
    $this->get('/definitely-not-a-page')
        ->assertNotFound()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $this->getJson('/health')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('disallows crawling the whole site in robots.txt', function () {
    $robots = file_get_contents(public_path('robots.txt'));

    expect($robots)->toMatch('/^User-agent: \*$/m')
        ->and($robots)->toMatch('/^Disallow: \/$/m');
});

it('lets only Meta crawlers read the pages its app settings link to', function () {
    $robots = file_get_contents(public_path('robots.txt'));

    // Groups are separated by a blank line; find the one addressed to Meta.
    $groups = preg_split('/\n\s*\n/', trim(preg_replace('/^#.*\n/m', '', $robots)));
    $meta = collect($groups)->first(fn ($g) => str_contains($g, 'User-agent: facebookexternalhit'));

    expect($meta)->not->toBeNull()
        ->and($meta)->toMatch('/^Allow: \/data-deletion$/m')
        ->and($meta)->toMatch('/^Allow: \/legal\/$/m')
        ->and($meta)->toMatch('/^Disallow: \/$/m')
        ->and($meta)->not->toContain('User-agent: *');
});

it('leaves the data-deletion page indexable, for Meta to accept it', function () {
    $this->get('/data-deletion')->assertOk()->assertHeaderMissing('X-Robots-Tag');
});

it('keeps noindex on everything else, look-alike paths included', function () {
    $this->get('/data-deletion-extra')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get('/')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

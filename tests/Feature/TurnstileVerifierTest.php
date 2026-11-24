<?php

use App\Services\Turnstile\TurnstileVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 * Server-side Turnstile check. Enabled only with a secret configured and
 * outside 'local'; when enabled it fails CLOSED (OTP sends are paid actions).
 */

it('is disabled without a secret and then passes everything', function () {
    config(['services.turnstile.secret' => '']);
    Http::fake();

    $verifier = new TurnstileVerifier;

    expect($verifier->enabled())->toBeFalse()
        ->and($verifier->verify(null, '10.0.0.1'))->toBeTrue()
        ->and($verifier->verify('anything', '10.0.0.1'))->toBeTrue();

    Http::assertNothingSent();
});

it('is disabled in the local environment even with a secret', function () {
    config(['services.turnstile.secret' => 'ts-secret']);
    $this->app['env'] = 'local';
    Http::fake();

    $verifier = new TurnstileVerifier;

    expect($verifier->enabled())->toBeFalse()
        ->and($verifier->verify(null, '10.0.0.1'))->toBeTrue();

    Http::assertNothingSent();
});

it('accepts a token Cloudflare confirms', function () {
    config(['services.turnstile.secret' => 'ts-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    expect((new TurnstileVerifier)->verify('token', '10.0.0.1'))->toBeTrue();
});

it('rejects a token Cloudflare refuses', function () {
    config(['services.turnstile.secret' => 'ts-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

    expect((new TurnstileVerifier)->verify('token', '10.0.0.1'))->toBeFalse();
});

it('rejects a missing token without calling Cloudflare', function () {
    config(['services.turnstile.secret' => 'ts-secret']);
    Http::fake();

    expect((new TurnstileVerifier)->verify(null, '10.0.0.1'))->toBeFalse()
        ->and((new TurnstileVerifier)->verify('', '10.0.0.1'))->toBeFalse();

    Http::assertNothingSent();
});

it('fails closed when siteverify is unreachable', function () {
    config(['services.turnstile.secret' => 'ts-secret']);
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect((new TurnstileVerifier)->verify('token', '10.0.0.1'))->toBeFalse();
});

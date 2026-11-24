<?php

use App\Mail\LoginCode;
use App\Models\OtpCode;
use App\Services\Otp\OtpSendStatus;
use App\Services\Otp\OtpService;
use App\Services\Otp\OtpVerifyStatus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/*
 * The OTP core: hashing, TTL, single-use, wrong-entry blocking and the
 * layered send limits. Mail is faked so tests can read the plaintext code
 * back from the caught Mailable — no message ever leaves.
 */

const OTP_EMAIL = 'member@example.test';

beforeEach(function () {
    Mail::fake();
});

function otpService(): OtpService
{
    return new OtpService;
}

/** The plaintext of the last code the fake mailer caught. */
function lastCode(): ?string
{
    return Mail::sent(LoginCode::class)->last()?->code;
}

it('stores only a hash of the code and puts the plaintext in the mail', function () {
    $result = otpService()->send(OTP_EMAIL, '10.0.0.1', 'fp-1');

    expect($result->wasSent())->toBeTrue();

    $code = lastCode();
    expect($code)->toMatch('/^\d{6}$/');

    Mail::assertSent(LoginCode::class, fn (LoginCode $mail) => $mail->hasTo(OTP_EMAIL));

    $row = OtpCode::sole();
    expect($row->email)->toBe(OTP_EMAIL)
        ->and($row->code_hash)->not->toBe($code)
        ->and(Hash::check($code, $row->code_hash))->toBeTrue()
        ->and($row->consumed_at)->toBeNull()
        ->and($row->attempts)->toBe(0);
});

it('never logs the code', function () {
    Log::spy();

    otpService()->send(OTP_EMAIL, '10.0.0.1', 'fp-1');
    $code = lastCode();

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    expect($code)->not->toBeNull();
});

it('verifies the correct code and consumes it exactly once', function () {
    $service = otpService();
    $service->send(OTP_EMAIL, '10.0.0.1', 'fp-1');
    $code = lastCode();

    $first = $service->verify(OTP_EMAIL, $code);
    expect($first->isVerified())->toBeTrue()
        ->and(OtpCode::sole()->consumed_at)->not->toBeNull();

    // Replay of the same (now consumed) code must NOT verify again.
    $second = $service->verify(OTP_EMAIL, $code);
    expect($second->status)->toBe(OtpVerifyStatus::Expired);
});

it('reports a wrong code with the attempts left, then blocks the address on the second miss', function () {
    $service = otpService();
    $service->send(OTP_EMAIL, '10.0.0.1', 'fp-1');
    $code = lastCode();
    $wrong = $code === '000000' ? '111111' : '000000';

    $first = $service->verify(OTP_EMAIL, $wrong);
    expect($first->status)->toBe(OtpVerifyStatus::Invalid)
        ->and($first->attemptsLeft)->toBe(1);

    $second = $service->verify(OTP_EMAIL, $wrong);
    expect($second->status)->toBe(OtpVerifyStatus::Blocked)
        ->and($second->retryAfterSeconds)->toBe(180 * 60);

    // Blocked means blocked: even the CORRECT code is refused now…
    expect($service->verify(OTP_EMAIL, $code)->status)->toBe(OtpVerifyStatus::Blocked);

    // …and no new code is sent to the blocked address (fresh requester keys,
    // so it is the block refusing, not a send throttle).
    $send = $service->send(OTP_EMAIL, '10.0.0.9', 'fp-other');
    expect($send->status)->toBe(OtpSendStatus::Blocked)
        ->and($send->retryAfterSeconds)->toBeGreaterThan(0);
});

it('lifts the block after the configured window', function () {
    $service = otpService();
    $service->send(OTP_EMAIL, '10.0.0.1', 'fp-1');
    $service->verify(OTP_EMAIL, 'x00000');
    $service->verify(OTP_EMAIL, 'x00000'); // second miss → 3h block

    $this->travel(181)->minutes();

    // Both the block (3h) and the per-address send window have passed.
    $result = $service->send(OTP_EMAIL, '10.0.0.1', 'fp-1');
    expect($result->wasSent())->toBeTrue();
});

it('rejects an expired code and asks for a fresh one', function () {
    $service = otpService();
    $service->send(OTP_EMAIL, '10.0.0.1', 'fp-1');
    $code = lastCode();

    $this->travel(11)->minutes(); // TTL is 10 minutes

    expect($service->verify(OTP_EMAIL, $code)->status)->toBe(OtpVerifyStatus::Expired);
});

it('treats an address with no active code as expired (get a new code)', function () {
    expect(otpService()->verify(OTP_EMAIL, '123456')->status)->toBe(OtpVerifyStatus::Expired);
});

it('verifies against a factory-seeded active code', function () {
    OtpCode::factory()->create(['email' => OTP_EMAIL]);

    expect(otpService()->verify(OTP_EMAIL, '123456')->isVerified())->toBeTrue();
});

it('rejects a factory-expired code', function () {
    OtpCode::factory()->expired()->create(['email' => OTP_EMAIL]);

    expect(otpService()->verify(OTP_EMAIL, '123456')->status)->toBe(OtpVerifyStatus::Expired);
});

it('rejects a factory-consumed code', function () {
    OtpCode::factory()->consumed()->create(['email' => OTP_EMAIL]);

    expect(otpService()->verify(OTP_EMAIL, '123456')->status)->toBe(OtpVerifyStatus::Expired);
});

it('replaces the previous code on resend — only the newest one verifies', function () {
    $service = otpService();

    $service->send(OTP_EMAIL, '10.0.0.1', 'fp-1');
    $firstCode = lastCode();

    $this->travel(61)->seconds(); // clear the 1/min requester throttle

    $service->send(OTP_EMAIL, '10.0.0.1', 'fp-1');
    $secondCode = lastCode();

    expect(OtpCode::query()->whereNull('consumed_at')->count())->toBe(1);

    if ($firstCode !== $secondCode) {
        expect($service->verify(OTP_EMAIL, $firstCode)->status)->toBe(OtpVerifyStatus::Invalid);
    }
    expect($service->verify(OTP_EMAIL, $secondCode)->isVerified())->toBeTrue();
});

it('throttles a second send from the same requester within a minute', function () {
    $service = otpService();

    expect($service->send(OTP_EMAIL, '10.0.0.1', 'fp-1')->wasSent())->toBeTrue();

    $again = $service->send('other@example.test', '10.0.0.1', 'fp-1');
    expect($again->status)->toBe(OtpSendStatus::RateLimited)
        ->and($again->retryAfterSeconds)->toBeLessThanOrEqual(60);
});

it('caps the same requester at three sends per hour', function () {
    // Loosen the narrower limits so ONLY the hourly cap is in play.
    config(['otp.limits.per_requester_minute' => 100, 'otp.limits.per_address' => 100]);

    $service = otpService();

    foreach (range(1, 3) as $i) {
        expect($service->send(OTP_EMAIL, '10.0.0.1', 'fp-1')->wasSent())->toBeTrue();
    }

    $fourth = $service->send(OTP_EMAIL, '10.0.0.1', 'fp-1');
    expect($fourth->status)->toBe(OtpSendStatus::RateLimited)
        ->and($fourth->retryAfterSeconds)->toBeLessThanOrEqual(3600);
});

it('caps one address at two sends per window across different requesters', function () {
    $service = otpService();

    expect($service->send(OTP_EMAIL, '10.0.0.1', 'fp-1')->wasSent())->toBeTrue()
        ->and($service->send(OTP_EMAIL, '10.0.0.2', 'fp-2')->wasSent())->toBeTrue();

    $third = $service->send(OTP_EMAIL, '10.0.0.3', 'fp-3');
    expect($third->status)->toBe(OtpSendStatus::RateLimited);
});

it('trips the global fuse and reports the service unavailable', function () {
    config(['otp.limits.global_per_minute' => 2]);
    Log::spy();

    $service = otpService();

    expect($service->send('one@example.test', '10.0.0.1', 'fp-1')->wasSent())->toBeTrue()
        ->and($service->send('two@example.test', '10.0.0.2', 'fp-2')->wasSent())->toBeTrue();

    $third = $service->send('three@example.test', '10.0.0.3', 'fp-3');

    expect($third->status)->toBe(OtpSendStatus::Unavailable);
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message) => str_contains($message, 'global send fuse'),
    )->once();
});

it('drops the stored code, refunds the address allowance and keeps requester limits spent when the mailer fails', function () {
    Log::spy();
    // The first send hits a dead transport; the fake takes over again after.
    $mailer = Mail::partialMock();
    $mailer->shouldReceive('to')->once()->andThrow(new RuntimeException('smtp down'));
    $mailer->shouldReceive('to')->passthru();
    $service = otpService();

    expect($service->send(OTP_EMAIL, '10.0.0.1', 'fp-1')->status)->toBe(OtpSendStatus::Failed)
        ->and(OtpCode::count())->toBe(0);

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message) => str_contains($message, 'delivery failed'),
    )->once();

    // Requester limits stayed spent: the SAME requester is still throttled…
    expect($service->send(OTP_EMAIL, '10.0.0.1', 'fp-1')->status)->toBe(OtpSendStatus::RateLimited);

    // …but the address allowance was refunded: the full window (2) still fits.
    expect($service->send(OTP_EMAIL, '10.0.0.2', 'fp-2')->wasSent())->toBeTrue()
        ->and($service->send(OTP_EMAIL, '10.0.0.3', 'fp-3')->wasSent())->toBeTrue();
});

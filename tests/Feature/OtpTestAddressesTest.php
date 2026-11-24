<?php

use App\Mail\LoginCode;
use App\Models\OtpCode;
use App\Services\Otp\EmailAddress;
use App\Services\Otp\OtpSendStatus;
use App\Services\Otp\OtpService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/*
 * The demo/QA bypass (config('otp.test_addresses')): listed addresses take a
 * fixed code and never get a message. What these tests pin down is mostly
 * what the bypass does NOT loosen — it fails closed when misconfigured, it
 * leaves every other address alone, and it keeps the requester throttle that
 * bounds guessing, because for a listed address the code is the only
 * credential.
 */

const TEST_ADDRESS = 'demo@example.test';
const TEST_CODE = '481902';

beforeEach(function () {
    Mail::fake();
});

function testAddressService(): OtpService
{
    config(['otp.test_addresses' => [TEST_ADDRESS], 'otp.test_code' => TEST_CODE]);

    return new OtpService;
}

it('issues the fixed code to a test address without sending anything', function () {
    $result = testAddressService()->send(TEST_ADDRESS, '10.0.0.1', 'fp-1');

    expect($result->wasSent())->toBeTrue();
    Mail::assertNothingSent();

    $row = OtpCode::sole();
    expect($row->email)->toBe(TEST_ADDRESS)
        ->and(Hash::check(TEST_CODE, $row->code_hash))->toBeTrue();
});

it('logs the bypass instead of letting it pass silently', function () {
    Log::spy();

    testAddressService()->send(TEST_ADDRESS, '10.0.0.1', 'fp-1');

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message) => str_contains($message, 'test-address bypass'),
    )->once();
});

it('signs a test address in end to end with the fixed code', function () {
    $service = testAddressService();

    $service->send(TEST_ADDRESS, '10.0.0.1', 'fp-1');

    expect($service->verify(TEST_ADDRESS, TEST_CODE)->isVerified())->toBeTrue();
});

it('still refuses a wrong code on a test address', function () {
    $service = testAddressService();
    $service->send(TEST_ADDRESS, '10.0.0.1', 'fp-1');

    expect($service->verify(TEST_ADDRESS, '000000')->isVerified())->toBeFalse();
});

it('leaves every other address on the real delivery path', function () {
    testAddressService()->send('member@example.test', '10.0.0.1', 'fp-1');

    Mail::assertSent(LoginCode::class, fn (LoginCode $mail) => $mail->code !== TEST_CODE);
});

it('stays off when no test code is configured', function () {
    config(['otp.test_addresses' => [TEST_ADDRESS], 'otp.test_code' => '']);

    (new OtpService)->send(TEST_ADDRESS, '10.0.0.1', 'fp-1');

    // The listed address alone opens nothing: a real code went out as usual.
    Mail::assertSent(LoginCode::class, fn (LoginCode $mail) => preg_match('/^\d{6}$/', $mail->code) === 1);
});

it('stays off when the test code is not a code at all', function () {
    config(['otp.test_addresses' => [TEST_ADDRESS], 'otp.test_code' => 'true']);

    expect(EmailAddress::isTestAddress(TEST_ADDRESS))->toBeFalse();
});

it('matches a listed address however it is spelled', function () {
    config(['otp.test_addresses' => [TEST_ADDRESS], 'otp.test_code' => TEST_CODE]);

    expect(EmailAddress::isTestAddress(' Demo@Example.TEST '))->toBeTrue()
        ->and(EmailAddress::isTestAddress('demo2@example.test'))->toBeFalse();
});

it('does not cap a test address at the per-address send limit', function () {
    // Only the per-address cap (2 per window) is under test, so loosen the
    // requester throttles that would otherwise stop the loop first.
    config(['otp.limits.per_requester_minute' => 100, 'otp.limits.per_requester_hour' => 100]);
    $service = testAddressService();

    foreach (range(1, 5) as $i) {
        expect($service->send(TEST_ADDRESS, '10.0.0.1', 'fp-1')->wasSent())->toBeTrue();
    }
});

it('keeps the requester throttle that bounds guessing a test address', function () {
    $service = testAddressService();

    expect($service->send(TEST_ADDRESS, '10.0.0.1', 'fp-1')->wasSent())->toBeTrue();

    $again = $service->send(TEST_ADDRESS, '10.0.0.1', 'fp-1');
    expect($again->status)->toBe(OtpSendStatus::RateLimited);
});

it('lets a fresh code lift a block on a test address', function () {
    config(['otp.limits.per_requester_minute' => 100]);
    $service = testAddressService();

    $service->send(TEST_ADDRESS, '10.0.0.1', 'fp-1');
    $service->verify(TEST_ADDRESS, '000000');
    $service->verify(TEST_ADDRESS, '000000'); // second miss → the address blocks

    expect($service->blockSecondsRemaining(TEST_ADDRESS))->toBeGreaterThan(0);

    expect($service->send(TEST_ADDRESS, '10.0.0.1', 'fp-1')->wasSent())->toBeTrue()
        ->and($service->verify(TEST_ADDRESS, TEST_CODE)->isVerified())->toBeTrue();
});

it('does not lift a block on an ordinary address', function () {
    config(['otp.limits.per_requester_minute' => 100]);
    $service = testAddressService();

    $service->send('member@example.test', '10.0.0.1', 'fp-1');
    $service->verify('member@example.test', '000000');
    $service->verify('member@example.test', '000000');

    expect($service->send('member@example.test', '10.0.0.1', 'fp-1')->status)
        ->toBe(OtpSendStatus::Blocked);
});

<?php

use App\Models\ColorProfile;
use Illuminate\Support\Facades\Http;

/*
 * color:rule-check — today's thresholds, over the faces already measured.
 *
 * The command's whole value is that it compares two things honestly: what was
 * stored when the profile was measured, and what the rules would say about the
 * same numbers now. So what these tests are about is the comparison, not the
 * rules — the rules belong to the engine and are tested there, and the service
 * is faked here for the same reason every other external call is.
 *
 * The one thing that must never happen is a confident answer where there is no
 * comparison to make: a zone rejected on pixels that no longer exist is not
 * evidence that a threshold is wrong.
 */

beforeEach(function () {
    config(['services.pose.url' => 'http://pose.test']);
});

/** One zone as /color-rule-check reports it. */
function replayedZone(
    ?bool $usable,
    ?string $reason = null,
    ?string $decidedBy = null,
    array $checks = [],
    array $blindSpots = [],
): array {
    return [
        'zone' => 'x',
        'replayed' => $usable !== null,
        'usable' => $usable,
        'reason' => $reason,
        'decided_by' => $decidedBy,
        'checks' => $checks,
        'blind_spots' => $blindSpots,
    ];
}

/** The service's answer, with whatever zones a test needs. */
function fakeRuleCheck(array $zones): void
{
    Http::fake(['*/color-rule-check' => Http::response([
        'thresholds' => ['sclera_socket_light_ratio' => 0.55, 'sclera_under_skin_max_l' => 6.0],
        'zones' => $zones,
    ])]);
}

/** A measured profile carrying numbers and the verdicts they were given. */
function profileWithZones(array $zones, array $samples = ['skin' => ['L' => 84.8]]): ColorProfile
{
    return ColorProfile::factory()->measured()->create([
        'samples' => $samples,
        'zones' => $zones,
    ]);
}

it('says nothing has been measured rather than inventing a table', function () {
    $this->artisan('color:rule-check')
        ->expectsOutputToContain('No measured profiles to check.')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('reports a zone whose verdict the current thresholds would change', function () {
    /* The reason the command exists: a sclera refused when the profile was
     * measured, admitted by the thresholds as they stand now. Before the
     * numbers were kept, this question needed the photograph. */
    $profile = profileWithZones(
        ['sclera_left' => ['usable' => false, 'reason' => 'darker_than_skin']],
    );
    fakeRuleCheck([
        'sclera_left' => replayedZone(true, checks: [
            ['name' => 'not_darker_than_skin', 'reason' => 'darker_than_skin', 'passed' => true, 'value' => 14.8, 'limit' => 18.2, 'margin' => 3.4],
        ]),
    ]);

    $this->artisan('color:rule-check')
        ->expectsOutputToContain('sclera_left')
        ->expectsOutputToContain('now_usable')
        ->assertSuccessful();

    expect($profile->fresh()->zones['sclera_left']['usable'])->toBeFalse();
});

it('changes nothing it reads', function () {
    /* Read-only is a promise, not a description of the current implementation. */
    $profile = profileWithZones(['skin' => ['usable' => true, 'reason' => null]]);
    $before = $profile->fresh()->toArray();
    fakeRuleCheck(['skin' => replayedZone(true)]);

    $this->artisan('color:rule-check')->assertSuccessful();

    expect($profile->fresh()->toArray())->toBe($before);
});

it('does not call a zone rejected on pixels a disagreement', function () {
    /* The failure that would make the command dangerous. "too_few_pixels" was
     * decided on evidence that no longer exists, so a replay saying "usable"
     * is not news about the threshold — and printing it as "now_usable" would
     * invite somebody to loosen a rule that was never the problem. */
    profileWithZones(['sclera_left' => ['usable' => false, 'reason' => 'too_few_pixels']]);
    fakeRuleCheck(['sclera_left' => replayedZone(true)]);

    $this->artisan('color:rule-check')
        ->expectsOutputToContain('not_comparable')
        ->doesntExpectOutputToContain('now_usable')
        ->assertSuccessful();
});

it('does not compare a zone the replay could not judge', function () {
    profileWithZones(['hair' => ['usable' => false, 'reason' => 'overexposed']]);
    fakeRuleCheck(['hair' => replayedZone(null)]);

    $this->artisan('color:rule-check')
        ->expectsOutputToContain('not_comparable')
        ->assertSuccessful();
});

it('counts the outcomes and the reasons behind the rejections', function () {
    profileWithZones([
        'skin' => ['usable' => true, 'reason' => null],
        'right_cheek' => ['usable' => false, 'reason' => 'overexposed'],
        'sclera_left' => ['usable' => false, 'reason' => 'darker_than_skin'],
    ]);
    fakeRuleCheck([
        'skin' => replayedZone(true),
        'right_cheek' => replayedZone(false, 'overexposed', 'light_and_flat'),
        'sclera_left' => replayedZone(false, 'darker_than_skin', 'not_darker_than_skin'),
    ]);

    $this->artisan('color:rule-check')
        ->expectsOutputToContain('still_usable')
        ->expectsOutputToContain('still_rejected')
        ->expectsOutputToContain('Rejections, by reason:')
        ->expectsOutputToContain('darker_than_skin')
        ->assertSuccessful();
});

it('names the check that decided a zone and by how much', function () {
    /* "Rejected" is not reviewable. "Rejected by 6.6 on not_darker_than_skin"
     * says which number to move and roughly how far. */
    profileWithZones(['sclera_left' => ['usable' => false, 'reason' => 'darker_than_skin']]);
    fakeRuleCheck([
        'sclera_left' => replayedZone(false, 'darker_than_skin', 'not_darker_than_skin', checks: [
            ['name' => 'brighter_than_iris', 'reason' => 'not_brighter_than_iris', 'passed' => true, 'value' => 33.1, 'limit' => 8.0, 'margin' => 25.1],
            ['name' => 'not_darker_than_skin', 'reason' => 'darker_than_skin', 'passed' => false, 'value' => 24.8, 'limit' => 18.2, 'margin' => -6.6],
        ]),
    ]);

    // One substring, not two: the check and its margin have to be on the SAME
    // row to mean anything, and Mockery gives a written line to the first
    // matching expectation only, so two would not prove they were together.
    $this->artisan('color:rule-check')
        ->expectsOutputToContain('not_darker_than_skin | -6.6')
        ->assertSuccessful();
});

it('records which thresholds the answer was produced with', function () {
    /* An answer read back in six months must not need a guess about which
     * build produced it. */
    profileWithZones(['skin' => ['usable' => true, 'reason' => null]]);
    fakeRuleCheck(['skin' => replayedZone(true)]);

    $this->artisan('color:rule-check')
        ->expectsOutputToContain('sclera_socket_light_ratio')
        ->assertSuccessful();
});

it('replays only the profiles it was asked for', function () {
    $wanted = profileWithZones(['skin' => ['usable' => true, 'reason' => null]]);
    profileWithZones(['skin' => ['usable' => true, 'reason' => null]]);
    fakeRuleCheck(['skin' => replayedZone(true)]);

    $this->artisan('color:rule-check', ['--profile' => [$wanted->id]])
        ->expectsOutputToContain('1 profile(s)')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('sends the stored samples unchanged', function () {
    /* The engine gets exactly what was written down. Reshaping the samples on
     * the way out would mean replaying rules against numbers no profile ever
     * had. */
    profileWithZones(
        ['skin' => ['usable' => true, 'reason' => null]],
        ['skin' => ['L' => 84.831, 'a' => 10.771, 'b' => 6.894]],
    );
    fakeRuleCheck(['skin' => replayedZone(true)]);

    $this->artisan('color:rule-check')->assertSuccessful();

    Http::assertSent(fn ($request) => $request['samples'] === ['skin' => ['L' => 84.831, 'a' => 10.771, 'b' => 6.894]]);
});

it('prints the whole replay as JSON when asked', function () {
    profileWithZones(['sclera_left' => ['usable' => false, 'reason' => 'darker_than_skin']]);
    fakeRuleCheck(['sclera_left' => replayedZone(true)]);

    $this->artisan('color:rule-check', ['--json' => true])
        ->expectsOutputToContain('"verdict": "now_usable"')
        ->assertSuccessful();
});

it('fails loudly when the service cannot be reached', function () {
    /* Half an answer is worse than none here: somebody is about to move a
     * threshold on what this prints. */
    profileWithZones(['skin' => ['usable' => true, 'reason' => null]]);
    Http::fake(['*' => Http::response('', 500)]);

    $this->artisan('color:rule-check')->assertFailed();
});

it('fails loudly when the service has no rule checking to offer', function () {
    profileWithZones(['skin' => ['usable' => true, 'reason' => null]]);
    Http::fake(['*' => Http::response(['detail' => 'not installed'], 503)]);

    $this->artisan('color:rule-check')
        ->expectsOutputToContain('not installed')
        ->assertFailed();
});

<?php

use App\Models\Lead;
use App\Models\MarketingConsent;

/*
 * Contact and marketing are two different permissions.
 *
 * Handing over a contact is the condition for using the free service. Being sent
 * offers on it is a separate thing that has to be asked for, recorded, and
 * withdrawable at no cost — so it is an optional unticked box, it lives in its own
 * table, and a contact captured without it must stay unmarketable.
 */

it('captures a contact without any marketing permission by default', function () {
    $this->postJson('/api/lead', [
        'channel' => 'whatsapp',
        'contact' => '+971501234567',
    ])->assertStatus(201);

    // The contact is stored; the permission to market to it is simply absent,
    // which is unambiguous — there is no flag to misread.
    expect(Lead::count())->toBe(1)
        ->and(MarketingConsent::count())->toBe(0);
});

it('does not record marketing permission when the box is left unticked', function () {
    $this->postJson('/api/lead', [
        'channel' => 'email',
        'contact' => 'someone@example.test',
        'marketing_opt_in' => false,
    ])->assertStatus(201);

    expect(MarketingConsent::count())->toBe(0);
});

it('records marketing permission with its channel, version and timestamp when given', function () {
    $this->postJson('/api/lead', [
        'channel' => 'whatsapp',
        'contact' => '+971509876543',
        'marketing_opt_in' => true,
    ])->assertStatus(201);

    $consent = MarketingConsent::sole();

    expect($consent->lead_id)->toBe(Lead::sole()->id)
        ->and($consent->channel)->toBe('whatsapp')
        ->and($consent->consent_version)->toBe((string) config('consent.marketing.version'))
        ->and($consent->consented_at)->not->toBeNull()
        ->and($consent->isActive())->toBeTrue();
});

it('still captures the contact when marketing is refused — the service does not depend on it', function () {
    $refused = $this->postJson('/api/lead', [
        'channel' => 'email',
        'contact' => 'no-thanks@example.test',
        'marketing_opt_in' => false,
    ])->assertStatus(201);

    // Same outcome as opting in: a usable lead id, so refusing costs nothing.
    expect($refused->json('lead_id'))->not->toBeNull();
});

it('unsubscribes a contact, keeping the record as proof of the opt-out', function () {
    $lead = Lead::factory()->create(['channel' => 'email', 'contact' => 'bye@example.test']);
    MarketingConsent::factory()->email()->create(['lead_id' => $lead->id]);

    $this->artisan('app:unsubscribe-contact', ['contact' => 'bye@example.test'])
        ->assertSuccessful();

    $consent = MarketingConsent::sole();

    // Stamped, not deleted: a missing row would read as "never subscribed" and
    // lose the fact that they were, and then opted out.
    expect($consent->unsubscribed_at)->not->toBeNull()
        ->and($consent->isActive())->toBeFalse()
        ->and(Lead::find($lead->id))->not->toBeNull();
});

it('unsubscribes every record for the same contact, not just one', function () {
    // The same person may have come back more than once; a partial unsubscribe is
    // not an unsubscribe.
    foreach (range(1, 3) as $i) {
        $lead = Lead::factory()->create(['channel' => 'email', 'contact' => 'repeat@example.test']);
        MarketingConsent::factory()->email()->create(['lead_id' => $lead->id]);
    }

    $this->artisan('app:unsubscribe-contact', ['contact' => 'repeat@example.test'])->assertSuccessful();

    expect(MarketingConsent::subscribed()->count())->toBe(0);
});

it('leaves other people alone when unsubscribing', function () {
    $target = Lead::factory()->create(['channel' => 'email', 'contact' => 'target@example.test']);
    $other = Lead::factory()->create(['channel' => 'email', 'contact' => 'other@example.test']);
    MarketingConsent::factory()->email()->create(['lead_id' => $target->id]);
    MarketingConsent::factory()->email()->create(['lead_id' => $other->id]);

    $this->artisan('app:unsubscribe-contact', ['contact' => 'target@example.test'])->assertSuccessful();

    expect(MarketingConsent::subscribed()->count())->toBe(1)
        ->and(MarketingConsent::subscribed()->sole()->lead_id)->toBe($other->id);
});

it('does not move the opt-out date when unsubscribing twice', function () {
    $lead = Lead::factory()->create(['channel' => 'email', 'contact' => 'twice@example.test']);
    $consent = MarketingConsent::factory()->email()->unsubscribed()->create(['lead_id' => $lead->id]);
    $original = $consent->unsubscribed_at;

    $this->artisan('app:unsubscribe-contact', ['contact' => 'twice@example.test'])->assertSuccessful();

    expect($consent->refresh()->unsubscribed_at->timestamp)->toBe($original->timestamp);
});

it('changes nothing for a contact that never asked for marketing', function () {
    Lead::factory()->create(['channel' => 'email', 'contact' => 'quiet@example.test']);

    $this->artisan('app:unsubscribe-contact', ['contact' => 'quiet@example.test'])
        ->expectsOutputToContain('no active marketing permission')
        ->assertSuccessful();
});

it('drops marketing permission along with the contact when the contact is erased', function () {
    $lead = Lead::factory()->create(['channel' => 'email', 'contact' => 'erase@example.test']);
    MarketingConsent::factory()->email()->create(['lead_id' => $lead->id]);

    $this->artisan('app:delete-user-data', ['--email' => 'erase@example.test', '--force' => true])
        ->assertSuccessful();

    expect(MarketingConsent::count())->toBe(0);
});

it('offers the marketing box unticked, separately from the required contact field', function () {
    $html = $this->get('/ai-fitting-room')->assertOk()->getContent();

    expect($html)->toContain(config('consent.marketing.text'));

    // Not pre-filled: the input carries no checked attribute...
    $checkbox = Str::of($html)->after('x-model="marketingOptIn"')->before('>')->toString();
    expect($checkbox)->not->toContain('checked');

    // ...and it is its own control, not welded to the contact input.
    expect($html)->toContain('class="marketing-row"');
});

it('no longer tells people their contact is where the result gets delivered', function () {
    // The result opens in the browser. Calling the contact a delivery address
    // dressed a lead capture up as a service step.
    $html = $this->get('/ai-fitting-room')->assertOk()->getContent();

    expect($html)->not->toContain('Куди надіслати ваш лукбук');
});

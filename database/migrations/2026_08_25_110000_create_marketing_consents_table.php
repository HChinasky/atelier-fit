<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consent to receive MARKETING on a captured contact.
 *
 * Kept apart from the lead itself, deliberately. Handing over a contact detail is
 * the condition for using the service; being sent offers on it is a different
 * purpose, and one that needs its own freely-given, recorded, withdrawable
 * consent. Storing it as a flag on `leads` would blur exactly the line the audit
 * said we had blurred — a lead with no row here has simply not agreed to
 * marketing, and that is unambiguous.
 *
 * unsubscribed_at is the suppression signal: withdrawal must be free and must
 * stick, so the record is kept and stamped rather than deleted. A deleted row
 * would read as "never consented", losing the proof that they were subscribed and
 * then opted out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_consents', function (Blueprint $table) {
            $table->id();

            // Dies with the contact it belongs to.
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();

            // The channel consented to — marketing consent is per channel, not
            // blanket permission to use any contact detail we happen to hold.
            $table->enum('channel', ['whatsapp', 'email']);

            // Which wording was agreed to (config/consent.php → marketing.version).
            $table->string('consent_version', 32);

            $table->timestamp('consented_at');

            // Set when they opt out. Null = currently subscribed.
            $table->timestamp('unsubscribed_at')->nullable();

            $table->ipAddress('ip')->nullable();

            $table->timestamps();

            // The sending side asks "who may I contact on this channel" — the
            // partial index answers exactly that.
            $table->index(['channel', 'unsubscribed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_consents');
    }
};

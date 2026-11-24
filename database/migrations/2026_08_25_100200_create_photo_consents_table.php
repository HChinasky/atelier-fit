<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof that a visitor gave explicit consent before their photo was processed.
 *
 * Consent that cannot be demonstrated is, in practice, no consent at all, so the
 * record has to survive the photo it authorised: photos go in 72 hours, this row
 * lives for the consent retention period (config/privacy.php).
 *
 * The VERSION is the point of the table. Consent is only valid for the wording the
 * person actually saw, so each row pins the version of the text in force at the
 * time; when the wording, the AI provider or the retention period changes
 * materially, the version changes and consent is asked again.
 *
 * Deliberately minimal: no name, no contact, no photo reference. IP and user agent
 * are kept because they are what makes a consent record evidence rather than an
 * assertion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_consents', function (Blueprint $table) {
            $table->id();

            // The wording the person agreed to (config/consent.php version).
            $table->string('consent_version', 32);

            // When they agreed. Distinct from created_at, which is a row fact.
            $table->timestamp('accepted_at');

            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();

            // The retention sweep walks this by age.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_consents');
    }
};

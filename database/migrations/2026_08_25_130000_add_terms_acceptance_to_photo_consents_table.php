<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records acceptance of the Terms alongside the photo consent.
 *
 * Both are given on the same screen at the same moment, so they belong in the
 * same row: one insert, one timestamp, and no way for the record of the consent
 * to survive without the record of the acceptance that came with it.
 *
 * Nullable because rows written before this existed have no acceptance to
 * record, and inventing one would be worse than admitting the gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_consents', function (Blueprint $table) {
            $table->string('terms_version', 32)->nullable()->after('consent_version');
            $table->timestamp('terms_accepted_at')->nullable()->after('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('photo_consents', function (Blueprint $table) {
            $table->dropColumn(['terms_version', 'terms_accepted_at']);
        });
    }
};

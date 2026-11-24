<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A code row no longer always carries a hash. With the Twilio Verify driver
 * the provider holds the code and never hands it over, so the row is pure
 * bookkeeping — TTL, attempts, single-use — and code_hash stays null. It is
 * still filled in whenever the APP owns the code (the local driver, and the
 * test-number bypass under any driver).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('code_hash')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows issued by a provider-held-code driver have nothing to put back,
        // so they cannot be made NOT NULL again; they are spent codes by the
        // time this could run, and dropping them is the honest reversal.
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('code_hash')->nullable(false)->change();
        });
    }
};

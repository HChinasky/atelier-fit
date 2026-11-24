<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Passwordless phone login (Stage B2). Users are created at first OTP
     * verification knowing nothing but the phone, so the skeleton's
     * name/email/password become nullable; phone is the unique login handle
     * and phone_verified_at marks the first successful verification (the
     * one-time credit grant in Stage B3 keys off it).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->unique()->after('id');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');

            $table->string('name')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'phone_verified_at']);

            // NOTE: reversing NULLable → NOT NULL fails if phone-only users
            // exist; clear or backfill them first. Acceptable for a rollback.
            $table->string('name')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};

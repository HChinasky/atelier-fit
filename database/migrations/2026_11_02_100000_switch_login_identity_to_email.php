<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sign-in moves from a WhatsApp number to an e-mail address (Google,
     * Apple or a code sent by mail). The e-mail becomes the login handle, so
     * everything that was keyed by the phone is re-keyed:
     *
     * - users: the phone columns go. Accounts that only ever had a phone have
     *   no handle left to sign in with, so they are removed with everything
     *   that cascades from them (agreed before this change: no migration of
     *   phone accounts).
     * - otp_codes: codes are issued to an e-mail now. Outstanding codes are
     *   dropped rather than translated — they live ten minutes.
     * - welcome_grants: the once-ever marker is a hash of the e-mail from now
     *   on, so the column is renamed to say what it holds. Existing phone
     *   hashes stay: they still block nothing useful, and sweeping them is the
     *   retention job's business, not this migration's.
     */
    public function up(): void
    {
        DB::table('otp_codes')->delete();

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropIndex(['phone']);
            $table->renameColumn('phone', 'email');
        });
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('email', 254)->change();
            $table->index('email');
            $table->string('code_hash')->nullable(false)->change();
        });

        DB::table('users')->whereNull('email')->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn(['phone', 'phone_verified_at']);
        });

        Schema::table('welcome_grants', function (Blueprint $table) {
            $table->renameColumn('phone_hash', 'identity_hash');
        });
    }

    public function down(): void
    {
        Schema::table('welcome_grants', function (Blueprint $table) {
            $table->renameColumn('identity_hash', 'phone_hash');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->unique()->after('id');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
        });

        DB::table('otp_codes')->delete();

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->renameColumn('email', 'phone');
        });
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('phone', 20)->change();
            $table->index('phone');
            $table->string('code_hash')->nullable()->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An administrator flag on the account.
 *
 * The project had no notion of a role until now: accounts are created by phone
 * number through WhatsApp OTP and every member is equal. The colour-analysis
 * tool is an internal instrument for checking the engine, so it needs one, and
 * a boolean is the smallest thing that answers the only question asked of it.
 *
 * There is deliberately NO way to grant it from the interface. It is set by
 * hand, in the database, by someone with access to it — the same bar as
 * deploying. A self-service path would be a privilege-escalation surface built
 * for a feature with one user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};

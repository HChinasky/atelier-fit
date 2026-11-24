<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the activity signal the account-retention rule needs.
 *
 * Accounts are deleted after a period of INACTIVITY (config/privacy.php), which
 * requires knowing when the account was last used. Nothing recorded that before:
 * a passwordless sign-in neither writes to the row nor touches updated_at, so
 * updated_at stayed pinned at creation and would have made every old account look
 * abandoned. PhoneAuthenticator now stamps this column on every login.
 *
 * Backfilled from created_at so existing accounts start with a truthful floor
 * rather than a null the cleanup would have to guess about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable()->after('phone_verified_at');
        });

        DB::table('users')->whereNull('last_active_at')->update([
            'last_active_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_active_at');
        });
    }
};

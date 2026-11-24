<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the welcome-grant hash an end date.
 *
 * The hash exists so a phone number cannot claim the welcome bonus twice, which
 * meant keeping it forever — the one record with no retention period at all, and
 * the one the audit kept returning to. It only has to outlive the ACCOUNT,
 * though, not the person: once the account is gone and enough time has passed
 * that re-registering is no longer the same attempt at the bonus, the hash has
 * done its job.
 *
 * account_removed_at is stamped when the account behind the hash is erased, and
 * the retention sweep deletes the row a configured period after that. A hash
 * whose account is still alive has no date and is not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('welcome_grants', function (Blueprint $table) {
            $table->timestamp('account_removed_at')->nullable()->after('phone_hash');
            $table->index('account_removed_at');
        });
    }

    public function down(): void
    {
        Schema::table('welcome_grants', function (Blueprint $table) {
            $table->dropIndex(['account_removed_at']);
            $table->dropColumn('account_removed_at');
        });
    }
};

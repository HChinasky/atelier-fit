<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the private storage keys of a generation's result images.
 *
 * result_urls holds SIGNED urls, which expire and cannot be turned back into a
 * storage key — so with them alone we could never delete a result on request, only
 * wait for the lifecycle rule. Erasure on request is a published right, so the keys
 * have to be kept next to the urls. Null for generations created before this, and
 * for any that never produced a result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->jsonb('result_keys')->nullable()->after('result_urls');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('result_keys');
        });
    }
};

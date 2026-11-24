<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the person agreed to have their face photo analysed for a colour
 * season. A member upload requires the tick; the row keeps the moment of it,
 * because a consent that is not recorded is a consent nobody can show.
 * Nullable: the internal admin and stylist uploads predate the checkbox and
 * are not the member's own analysis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('color_profiles', function (Blueprint $table) {
            $table->timestamp('consented_at')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('color_profiles', function (Blueprint $table) {
            $table->dropColumn('consented_at');
        });
    }
};

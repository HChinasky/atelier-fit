<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The engine's per-zone verdict.
 *
 * "sclera: null" in samples is not an answer anybody can act on: a closed eye, a
 * mask that missed, an eye below the resolution floor and two eyes that
 * disagreed all look identical from there. The engine reports the reason now, so
 * the page can say which it was instead of leaving a blank row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('color_profiles', function (Blueprint $table) {
            $table->jsonb('zones')->nullable()->after('samples');
        });
    }

    public function down(): void
    {
        Schema::table('color_profiles', function (Blueprint $table) {
            $table->dropColumn('zones');
        });
    }
};

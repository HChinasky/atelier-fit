<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the engine's classifier said.
 *
 * `season` already existed and stayed null while there was no classifier; it now
 * holds a season slug. Three things join it:
 *
 *   contrast    — the distances between a person's own colours, which the
 *                 chroma axis is partly built on and the page shows as a number
 *   seasons     — the top TWO with their scores, because the neighbouring season
 *                 is a real call for anybody sitting on a boundary
 *   classifier  — which version of the classifier DATA decided it, whether that
 *                 data is still a draft, and whether the margin was thin
 *
 * `classifier` is not decoration. Every anchor point and prototype lives in a
 * versioned data file, so without it a season slug is a claim with no date on
 * it: two profiles reading 'deep_winter' six months apart may have been decided
 * by different numbers, and nothing else in the row would show it.
 *
 * Privacy: these are the same measurements as the samples — a reading of a face
 * — and they live under the same retention as the rest of color_profiles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('color_profiles', function (Blueprint $table) {
            $table->jsonb('contrast')->nullable()->after('axes');
            $table->jsonb('seasons')->nullable()->after('season');
            $table->jsonb('classifier')->nullable()->after('seasons');
        });
    }

    public function down(): void
    {
        Schema::table('color_profiles', function (Blueprint $table) {
            $table->dropColumn(['contrast', 'seasons', 'classifier']);
        });
    }
};

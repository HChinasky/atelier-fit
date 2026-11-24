<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The member's season, pinned: the analysis whose palette is theirs.
 *
 * Until now "their season" was whichever analysis was newest, so a retake that
 * came back refused or unplaced took the season away with it. The pointer
 * moves only when a member's own analysis places a season, and it is the one
 * thing a stylist's correction will later write to.
 *
 * nullOnDelete: an analysis can be removed (with its account, or by an admin)
 * and the member is then simply without a pinned season, not broken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('color_profile_id')->nullable()->after('is_stylist')
                ->constrained('color_profiles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('color_profile_id');
        });
    }
};

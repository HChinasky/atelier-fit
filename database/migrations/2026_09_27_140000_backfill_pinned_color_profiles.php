<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pin the season of members who had one before pinning existed.
 *
 * users.color_profile_id is moved by AnalyzeColorProfile, which only ran for
 * analyses made after it shipped; everyone analysed earlier was left without
 * a pinned season (and so without the catalog's "Мої кольори" chip). This
 * gives them the one the job would have pinned: their latest analysis that
 * placed a season, from their own consented photo — never an /admin/color
 * upload, which may be somebody else's face.
 *
 * Only empty pointers are filled, so it is safe to run again and never
 * overrides a season pinned since. down() leaves the pins: undoing them would
 * take away seasons members can see.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE users
            SET color_profile_id = latest.id
            FROM (
                SELECT DISTINCT ON (user_id) id, user_id
                FROM color_profiles
                WHERE status = 'ok'
                  AND season IS NOT NULL
                  AND consented_at IS NOT NULL
                  AND user_id IS NOT NULL
                ORDER BY user_id, created_at DESC, id DESC
            ) AS latest
            WHERE users.id = latest.user_id
              AND users.color_profile_id IS NULL
            SQL);
    }

    public function down(): void
    {
        //
    }
};

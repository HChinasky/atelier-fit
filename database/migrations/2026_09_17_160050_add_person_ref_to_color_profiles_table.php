<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which person a profile is of, and what light it was shot in.
 *
 * Calibrating white balance needs the same face under different lighting, and
 * nothing in a colour profile says two of them are the same face. The uploader
 * types a short handle — `p001` — and that is all the link there is.
 *
 * A free string on purpose, and NOT a foreign key to users. The person in the
 * photograph is frequently not the person holding the account, the handle is
 * meant to be typed from a sheet of paper next to the camera, and turning it
 * into a relationship would make an identity out of what is deliberately only
 * a label. It is exported as-is, which is safe precisely because it is `p001`
 * and not a name: whoever holds the sheet can resolve it, and the dataset
 * cannot.
 *
 * `lighting` is the engine's own Lighting enum, the same vocabulary the fixture
 * filenames use (services/color-engine/src/color_engine/fixtures.py), so a
 * calibration set gathered here and one gathered on disk are the same set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('color_profiles', function (Blueprint $table) {
            $table->string('person_ref')->nullable()->after('user_id');
            $table->string('lighting')->nullable()->after('person_ref');

            // "Every shot of p001" is the only query these support.
            $table->index('person_ref');
        });
    }

    public function down(): void
    {
        Schema::table('color_profiles', function (Blueprint $table) {
            $table->dropIndex(['person_ref']);
            $table->dropColumn(['person_ref', 'lighting']);
        });
    }
};

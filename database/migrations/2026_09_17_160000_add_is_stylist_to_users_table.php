<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second privileged flag, for the person whose judgement the classifier is
 * being calibrated against.
 *
 * A boolean beside is_admin rather than a `role` column or a roles table, and
 * that is a deliberate choice about what this system actually has: two
 * capabilities, both granted from a shell, neither exclusive of the other. A
 * `role` column would say they are exclusive, which is wrong — the one person
 * who currently has either has both. A roles table would be three tables and a
 * pivot to express what two booleans express, with no second consumer waiting
 * for it.
 *
 * Both flags are absent from $fillable, so no ordinary ->fill() can set either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A stylist may label colour profiles. Nothing else: not the upload
            // form, not another admin's profiles, not the rest of the site.
            $table->boolean('is_stylist')->default(false)->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_stylist');
        });
    }
};

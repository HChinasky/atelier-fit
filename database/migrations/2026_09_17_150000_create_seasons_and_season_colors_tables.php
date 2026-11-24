<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The 12 seasons and their palettes.
 *
 * These live in the database rather than in the engine because a palette is
 * PROJECT data, not measurement. The engine measures a face; which colours a
 * stylist recommends for that reading is a judgement she has to be able to make,
 * revise and disagree with, and that means rows somebody can edit rather than a
 * file somebody has to open a pull request against.
 *
 * What is seeded from database/seeders/data/season_palettes.json is marked
 * source='generator'. It is a DRAFT — generated from the engine's season
 * prototypes, never chosen by a stylist and never copied from anybody else's
 * palette — and is_draft says so on every row. A row a stylist edits is marked
 * source='stylist', and the seeder will never touch it again.
 *
 * Names and descriptions are deliberately NOT here. They are copy, they are
 * translated, and they belong in lang/{locale}/seasons.php with the rest of the
 * site's words. The slug is the contract; everything readable hangs off it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();

            // One of the engine's 12 Season slugs. Never localised, never
            // renamed: it is what a color_profiles row points at.
            $table->string('slug')->unique();

            // Display order, as the generator laid them out: grouped by the axis
            // each season leads on, so the list reads like the system it comes
            // from rather than like an alphabet.
            $table->unsignedSmallInteger('sort')->default(0);

            $table->timestamps();
        });

        Schema::create('season_colors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('season_id')->constrained()->cascadeOnDelete();

            // sRGB, uppercase, with the hash: '#4158A6'. What the page renders.
            $table->string('hex', 7);

            // The same colour in CIELAB (D65, 2°), taken off the rounded 8-bit
            // value so the two always agree. Columns rather than JSON because
            // matching a garment to a palette is a distance calculation, and a
            // distance calculation over jsonb is a distance calculation nobody
            // can index.
            $table->decimal('lab_l', 6, 2);
            $table->decimal('lab_a', 6, 2);
            $table->decimal('lab_b', 6, 2);

            // 'neutrals', 'base', 'accents' — recommended — or 'avoid'.
            // Quoted by the query builder; `group` is reserved in Postgres.
            $table->string('group');

            // Denormalised from the group on purpose: "show me what she should
            // wear" is one WHERE clause, and the page asks it on every render.
            $table->boolean('is_recommended')->default(true);

            $table->unsignedSmallInteger('sort')->default(0);

            // True while nobody with an eye has looked at this colour. Every
            // surface that shows a draft row has to say so.
            $table->boolean('is_draft')->default(true);

            // 'generator' — written by SeasonPaletteSeeder, and the only rows it
            // will ever delete or rewrite. 'stylist' — chosen by a person, and
            // untouchable by any seeder.
            $table->enum('source', ['generator', 'stylist'])->default('generator');

            $table->timestamps();

            // Every read is "this season's palette, in order".
            $table->index(['season_id', 'group', 'sort']);
            $table->index(['season_id', 'is_recommended']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_colors');
        Schema::dropIfExists('seasons');
    }
};

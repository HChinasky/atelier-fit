<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a product's measured colour sits against each season's palette: one row
 * per product and season, rewritten whole whenever the product is measured or
 * the palettes change (catalog:measure-colors --rematch).
 *
 * Precomputed rather than worked out per page view: the comparison is a
 * CIEDE2000 distance to every swatch of every season, and "the products that
 * suit a soft summer, best first" has to be one indexed query on a catalog of
 * any size.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_season_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('season', 32);
            $table->boolean('suits');
            $table->float('score');
            $table->float('delta_e');
            $table->string('palette_group', 16)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'season']);
            $table->index(['season', 'suits', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_season_matches');
    }
};

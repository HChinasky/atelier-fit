<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What colour a product is, as the colour engine measured it from its photo.
 *
 * color is the engine's GarmentResult as it serialises it, plus the image URL
 * it was measured from — so a feed that swaps the photo is seen as unmeasured
 * again. JSONB, like every other engine payload: the engine owns that shape.
 *
 * color_status is pulled out as a column because it is what the measuring
 * command filters on: ok | rejected (the engine looked and could not measure,
 * e.g. a white dress on white) | failed (the photo could not be fetched, or
 * the service refused).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->jsonb('color')->nullable()->after('raw');
            $table->string('color_status', 16)->nullable()->after('color');
            $table->timestamp('color_measured_at')->nullable()->after('color_status');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['color', 'color_status', 'color_measured_at']);
        });
    }
};

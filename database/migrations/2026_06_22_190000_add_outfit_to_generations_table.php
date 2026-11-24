<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outfit chaining reuses the generations table: one Generation still produces
     * ONE final result, but an outfit involves several products tried on in
     * sequence. Two nullable columns carry that — single try-ons leave both null
     * and behave exactly as before.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // Ordered set of product ids for an OUTFIT, in wearing order (base layer
            // first). Null = a single-garment generation (uses product_id only). Its
            // presence is what marks a generation as an outfit. JSONB per the
            // "raw/payload as jsonb, not extra tables" rule.
            $table->jsonb('product_ids')->nullable()->after('product_id');

            // Live progress for the chain: which layer (1-based) is being tried on
            // right now, so the loader can show an honest "item N of M". Null for
            // single try-ons.
            $table->unsignedTinyInteger('outfit_step')->nullable()->after('fashn_prediction_id');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn(['product_ids', 'outfit_step']);
        });
    }
};

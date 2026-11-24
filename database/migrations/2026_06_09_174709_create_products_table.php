<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Feed product id; the daily import upserts ON CONFLICT (external_id).
            $table->string('external_id')->unique();

            $table->string('brand_name');
            $table->string('product_name');

            // Exact monetary value — decimal, never float (no rounding drift).
            $table->decimal('price_aed', 10, 2);

            $table->string('image_flat_url');
            $table->string('shop_url');

            // FASHN category. enum() on Postgres compiles to varchar + a CHECK
            // constraint: DB-level integrity without the rigidity of a native
            // PG ENUM type (which needs ALTER TYPE to change values).
            $table->enum('category', ['upper_body', 'lower_body', 'dresses']);

            // Is the flat product photo usable for virtual try-on.
            $table->boolean('flat_bg_verified')->default(false);

            // Full raw feed object, stored as native jsonb.
            $table->jsonb('raw');

            $table->timestamps();
        });

        // Partial index for the catalog's hot path: GET /api/catalog returns
        // ONLY verified products (WHERE flat_bg_verified = true). A partial
        // index stays small and covers exactly that filter.
        DB::statement(
            'CREATE INDEX products_verified_partial_index ON products (flat_bg_verified) WHERE flat_bg_verified = true'
        );

        // No GIN index on raw: nothing queries inside the JSON yet (catalog reads
        // promoted columns), and GIN would add write cost to the daily upsert.
        // Add it in the phase that actually needs JSON containment/path lookups.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

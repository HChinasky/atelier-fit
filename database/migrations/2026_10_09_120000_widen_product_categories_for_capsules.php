<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Capsules dress a whole look, so the catalog now holds two categories the
 * try-on engine does not: shoes and headwear. They are catalog pieces only —
 * Product::TRY_ON_CATEGORIES keeps them out of /api/catalog, the dashboard and
 * the generation endpoints — and the products CHECK constraint (created by the
 * enum() column) is widened to accept them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_category_check');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_category_check CHECK (category IN ('upper_body', 'lower_body', 'dresses', 'shoes', 'headwear'))");
    }

    public function down(): void
    {
        // The narrower constraint cannot hold the new rows; they go with it.
        DB::table('products')->whereIn('category', ['shoes', 'headwear'])->delete();

        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_category_check');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_category_check CHECK (category IN ('upper_body', 'lower_body', 'dresses'))");
    }
};

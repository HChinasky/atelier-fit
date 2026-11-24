<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The category the FEED files a product under ("Платья", "Юбки", "Dresses"),
 * kept next to the try-on region in `category`. The region is what the engine
 * needs and knows only three values; the feed's own word is what a person
 * filters by, and the account's category chips are built from it. Nullable:
 * a feed may not say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('feed_category')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('feed_category');
        });
    }
};

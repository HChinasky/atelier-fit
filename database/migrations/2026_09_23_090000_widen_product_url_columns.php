<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affiliate links do not fit in varchar(255). An Amazon Associates
 * DetailPageURL carries the search context, the SiteStripe tracking token and
 * the partner tag and runs to 600-900 characters; truncating it would strip
 * the attribution the buy button exists to carry, and Postgres refuses the
 * insert rather than truncate anyway. Both URL columns become text: the value
 * is stored exactly as the feed gave it, whatever its length.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('shop_url')->change();
            $table->text('image_flat_url')->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('shop_url')->change();
            $table->string('image_flat_url')->change();
        });
    }
};

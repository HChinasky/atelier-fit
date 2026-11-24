<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member followed a product's "buy" link out to the shop.
 *
 * The one number the colour flow is for: of the people shown pieces in their
 * colours, how many went to buy one. source says where the click came from
 * (the colour page, the catalog), so the two can be told apart.
 *
 * No IP, no user agent: the member is known, and nothing else is needed to
 * count. Rows go with the account (cascade) or the product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('season', 32)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_clicks');
    }
};

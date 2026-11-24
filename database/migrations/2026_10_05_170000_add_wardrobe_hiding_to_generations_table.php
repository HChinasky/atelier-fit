<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the member took out of their wardrobe.
 *
 * "Видалити цю добірку" and the per-piece close HIDE, they do not delete: the
 * generation row is what the daily rate limit and the credit ledger count, so
 * erasing it from the wardrobe would hand the member their quota back. The
 * images still go with the usual 72-hour photo lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->timestamp('wardrobe_hidden_at')->nullable();
            $table->jsonb('wardrobe_hidden_product_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn(['wardrobe_hidden_at', 'wardrobe_hidden_product_ids']);
        });
    }
};

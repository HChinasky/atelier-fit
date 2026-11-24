<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage keys of the branded share cards (Этап B1): {"feed": key, "story":
 * key}, composed after a successful generation. Keys only — the cards live in
 * the same private R2 prefix as the result (same 72h purge), and every URL to
 * them is a short-lived signed one minted at read time, never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->jsonb('share_keys')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('share_keys');
        });
    }
};

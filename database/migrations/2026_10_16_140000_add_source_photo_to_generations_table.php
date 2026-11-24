<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The upload a generation was made from, next to the processed copy it sent.
 *
 * The raw upload used to be deleted as soon as its processed copy was stored,
 * which broke a second look on the same photo: the page still held the upload's
 * key. It is now kept (each look is processed from it for its own category) and
 * recorded here, so erasure deletes it with the row instead of leaving it to the
 * 72-hour sweep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->string('source_photo')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('source_photo');
        });
    }
};

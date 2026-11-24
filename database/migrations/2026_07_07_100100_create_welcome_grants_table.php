<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per PHONE NUMBER that has ever received the welcome credits —
     * deliberately NOT keyed to users: deleting the account (coming later)
     * must not reopen the +4 grant for the same number. The number itself is
     * not stored (privacy: the marker outlives the account); only an HMAC
     * keyed by the app secret, so rows cannot be reversed into phones without
     * the key. The unique index is the once-ever guarantee, race-proof.
     */
    public function up(): void
    {
        Schema::create('welcome_grants', function (Blueprint $table) {
            $table->id();
            $table->string('phone_hash', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('welcome_grants');
    }
};

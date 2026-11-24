<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            // Contact channel. enum() compiles to varchar + a CHECK constraint on
            // Postgres — DB-level integrity without a rigid native ENUM type.
            $table->enum('channel', ['whatsapp', 'email']);

            // The contact itself (a phone number or an email, per channel).
            $table->string('contact');

            // Free-form tags as native jsonb (carries at least 'try-on-user').
            $table->jsonb('tags');

            // Submitter IP (varchar(45), fits IPv6). Nullable — best-effort.
            $table->ipAddress('ip')->nullable();

            // When the lead was successfully pushed to an external funnel. Stays
            // null until such a notifier is configured (none exists yet); the
            // name is vendor-neutral on purpose so any future tool can set it.
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};

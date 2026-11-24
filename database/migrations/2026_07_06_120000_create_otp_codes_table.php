<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time passwords for the WhatsApp phone login (Stage B).
     *
     * The code is stored ONLY as a hash (code_hash) — never in the open.
     * `attempts` counts WRONG entries against this code (the phone blocks
     * after max_failed_attempts); `consumed_at` marks a code as used, and
     * single-use is enforced by a conditional update on that column.
     */
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            // E.164 (+9715XXXXXXXX); indexed — verify looks up by phone.
            $table->string('phone', 20)->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};

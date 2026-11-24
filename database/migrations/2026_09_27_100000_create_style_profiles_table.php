<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The member's answers to the style quiz shown after sign-up: a handful about
 * colour (sent to the engine beside the photo) and a handful about shopping
 * (what to recommend). One row per member.
 *
 * The answers are one JSONB object rather than a column each: the quiz is copy
 * that will be reworded, grown and trimmed, and a migration per question would
 * outlive the question. StyleQuiz owns what a valid answer is.
 *
 * completed_at and skipped_at are separate, both nullable: the quiz is offered
 * once after sign-up and can be skipped, and "skipped" is an answer of its own
 * that stops it being offered again — a row with neither stamp was started
 * elsewhere (the hair question on the colour page) and still counts as unseen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('style_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->jsonb('answers')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('style_profiles');
    }
};

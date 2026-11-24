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
        Schema::create('generations', function (Blueprint $table) {
            $table->id();

            // The lead that triggered this generation (set once captured) and the
            // catalog item being tried on.
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // R2 key of the client photo (from the upload sub-step). Not a URL —
            // a short-lived pre-signed URL is minted at generation time.
            $table->string('client_photo');

            // Lifecycle of the generation. enum() → varchar + CHECK on Postgres.
            $table->enum('status', ['queued', 'processing', 'done', 'failed'])->default('queued');

            // FASHN prediction id returned by /v1/run, polled via /v1/status.
            $table->string('fashn_prediction_id')->nullable();

            // Result image URLs (jsonb) once completed; runtime error if failed.
            $table->jsonb('result_urls')->nullable();
            $table->text('error')->nullable();

            // Generation parameters kept for traceability. num_samples defaults to
            // the same modest value as config('services.fashn.num_samples') (2), so
            // a Generation created directly gets the same look count as one created
            // through the API. with_background defaults to false (the optional,
            // costlier background step is opt-in).
            $table->unsignedTinyInteger('num_samples')->default(2);
            $table->boolean('with_background')->default(false);

            // When the generation (and its photo) should be cleaned up — Phase 7.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('fashn_prediction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};

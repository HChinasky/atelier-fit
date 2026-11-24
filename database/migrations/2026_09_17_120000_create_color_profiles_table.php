<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seasonal colour analysis of a member's photo, produced by the color-engine
 * library behind pose-service.
 *
 * Two tables, and the split is the point. `color_profiles` holds NUMBERS — what
 * the engine measured. `color_profile_images` holds the photo and the rendered
 * debug views, which are personal data on a 60-hour clock. When the purge has
 * run, the image rows are gone and the profile is left carrying only what it
 * measured, which is exactly what the privacy policy promises.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('color_profiles', function (Blueprint $table) {
            $table->id();

            // Whose face it is. A profile never outlives its owner.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Lifecycle. 'rejected' is a normal outcome — the engine could not
            // measure THIS photo — and is deliberately distinct from 'failed',
            // which means our environment broke. enum() → varchar + CHECK on pgsql.
            $table->enum('status', ['pending', 'processing', 'ok', 'rejected', 'failed'])
                ->default('pending');

            // Why the engine refused, one of its RejectReason slugs. Set if and
            // only if status = 'rejected'; the UI maps it to copy via lang/.
            $table->string('reject_reason')->nullable();

            // The engine's contract, stored as it serialises it. jsonb rather
            // than columns because these are measurements we read back whole and
            // never query into, and the engine is still free to add fields.
            $table->jsonb('samples')->nullable();
            $table->jsonb('white_balance')->nullable();
            $table->jsonb('axes')->nullable();

            // Classification, when there is one. There is not yet: stage 3 of the
            // engine has not been written, so this stays null and the page says so.
            $table->string('season')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();

            // The self-reported answers this analysis was given, kept so a result
            // can be read back against what the person said about themselves.
            $table->jsonb('quiz')->nullable();

            // Which build of the engine produced it, and whether its sclera target
            // had been calibrated at the time. Both belong to the RESULT: the same
            // photo measured a month later may answer differently, and without
            // these two there is no way to know which numbers are comparable.
            $table->string('algo_version')->nullable();
            $table->boolean('target_calibrated')->nullable();

            // Where the analysis came from. 'auto' is the engine on a photo;
            // 'quiz' and 'stylist' are reserved for a human verdict later.
            $table->enum('source', ['auto', 'quiz', 'stylist'])->default('auto');

            // An environment failure, in words. Never a reject reason — that has
            // its own column — and never anything derived from the photo.
            $table->text('error')->nullable();

            $table->timestamps();

            // "this admin's recent profiles, newest first" is the only listing
            // the page has, so it is the index worth having.
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('color_profile_images', function (Blueprint $table) {
            $table->id();

            $table->foreignId('color_profile_id')->constrained()->cascadeOnDelete();

            // 'source' for the uploaded photo, otherwise the engine's own view
            // name (overlay, crop_left_eye, wb_before_after, ...).
            $table->string('kind');

            // Private storage coordinates. A KEY, never a URL: these are served
            // only through an authorised controller, never from the bucket.
            $table->string('disk');
            $table->string('path');

            // When the object behind this row is expected to be gone. The storage
            // objects themselves are swept by app:purge-expired-photos, which
            // works off the bucket listing under the shared photo prefix and so
            // needs no teaching about this table; this column is what lets the
            // same command drop the rows that pointed at them.
            $table->timestamp('expires_at')->index();

            $table->timestamps();

            // One row per view per profile.
            $table->unique(['color_profile_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('color_profile_images');
        Schema::dropIfExists('color_profiles');
    }
};

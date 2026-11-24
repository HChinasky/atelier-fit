<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a stylist says a face is, beside what the engine measured.
 *
 * A separate table and NOT a column on color_profiles, because the two are
 * different kinds of claim and mixing them would destroy the only thing this is
 * for. `color_profiles.season` is what the classifier computed; this is what a
 * person judged. Calibration is the comparison of the two, so the moment one
 * can overwrite the other there is nothing left to compare.
 *
 * One label per profile per person. Two stylists disagreeing about the same
 * face is a signal worth keeping — it is the honest upper bound on how well any
 * classifier could ever do — so the unique index is on the pair rather than on
 * the profile alone, and each person edits only their own row.
 *
 * Privacy: a label is a judgement about somebody's appearance, so it lives with
 * the profile and under the same retention. It survives the photo purge on
 * purpose: after the images are gone the row is still a complete record of
 * "these measurements, this verdict", which is the whole dataset.
 *
 * Open question: A stylist is a third party looking at a member's photo. That
 * is a separate purpose of processing from "analyse my colours for me" and may
 * need its own line in the consent. Flagged, not implemented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('color_profile_labels', function (Blueprint $table) {
            $table->id();

            $table->foreignId('color_profile_id')->constrained()->cascadeOnDelete();

            // Who judged it. Never exported — the dataset is measurements and
            // verdicts, not a record of who said what about whom.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The verdict. One of the engine's own 12 slugs, so the two are
            // directly comparable without a mapping table in between.
            $table->string('season');

            // A second season, when the face sits on a boundary. Optional, and
            // the instructions ask for it whenever confidence is not high: a
            // stylist hedging is information, not indecision.
            $table->string('season_alt')->nullable();

            // The axes, judged separately. These are what the anchor points are
            // eventually set from, and a stylist who is sure about the undertone
            // but unsure about the season should be able to say exactly that.
            $table->string('undertone')->nullable();   // warm | cool | neutral
            $table->string('depth')->nullable();       // light | medium | deep
            $table->string('chroma')->nullable();      // bright | soft

            // How sure they are. Required, because an unqualified verdict and a
            // guess weigh the same in a report that cannot tell them apart.
            $table->string('stylist_confidence');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['color_profile_id', 'user_id']);
            // "What is left to label" is the query this screen runs constantly.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('color_profile_labels');
    }
};

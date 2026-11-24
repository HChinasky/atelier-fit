<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Member try-on credits as an append-only ledger (Stage B3): the balance is
     * the SUM of deltas, never a mutable column — every grant, spend and refund
     * leaves its own row, which billing stages will need as history anyway.
     *
     * The (generation_id, reason) unique index is the idempotency lock: one
     * spend and at most one refund can ever exist per generation, so a retried
     * failure hook cannot refund twice (NULL generation_id rows — welcome
     * grants — are exempt per Postgres NULL semantics).
     */
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            // The ledger dies with the account; the anti-re-signup marker lives
            // in welcome_grants, which has no FK on purpose.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('delta');
            $table->string('reason', 20); // welcome | spend | refund
            $table->timestamps();

            $table->index('user_id');
            $table->unique(['generation_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};

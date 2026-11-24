<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bridge between the membership account and the (stateless) generation
     * API: a logged-in member gets a lead OWNED by their user, minted at page
     * render (see FittingRoomController). The generate endpoints recognize an
     * owned lead and debit the member's credit ledger instead of the guest
     * funnel allowance. Guest leads keep user_id NULL and behave as before.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};

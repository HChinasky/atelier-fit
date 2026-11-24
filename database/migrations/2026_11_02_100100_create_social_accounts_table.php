<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A sign-in identity at Google or Apple, tied to one of our accounts.
     *
     * The provider's subject id is the durable key (an e-mail at the provider
     * can change; the id cannot), unique per provider. The e-mail the provider
     * reported is kept beside it only as a record of how the link was made.
     * Deleting the account deletes its links.
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_id');
            $table->string('email', 254)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};

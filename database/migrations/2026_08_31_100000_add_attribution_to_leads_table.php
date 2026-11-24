<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-touch campaign attribution on the lead (Этап A1): which campaign
 * brought the visitor who eventually left this contact. Filled from the
 * session at lead creation; all nullable because most visitors arrive with no
 * campaign parameters at all. Internal-only columns — never rendered to the
 * visitor and never sent to a vendor as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('landing_path')->nullable();
            $table->string('referrer_host')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
                'utm_term', 'landing_path', 'referrer_host',
            ]);
        });
    }
};

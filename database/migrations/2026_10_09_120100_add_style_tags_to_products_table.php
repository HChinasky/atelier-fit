<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a piece is FOR, so capsules can be built per occasion.
 *
 *   - style: casual / formal / evening / business / sport, or null when the
 *     text does not say — never a guess;
 *   - occasion: office / evening / casual, or universal — a base piece that
 *     goes with any occasion. Unknown style means universal;
 *   - style_source: who decided — feed, parse (keywords), ai or manual. A
 *     higher source is never overwritten by a lower one (StyleTagger);
 *   - style_evidence: the keywords and the field they were found in, so a
 *     parsed tag can be checked and corrected later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('style', ['casual', 'formal', 'evening', 'business', 'sport'])->nullable()->after('feed_category');
            $table->enum('occasion', ['office', 'evening', 'casual', 'universal'])->default('universal')->after('style');
            $table->enum('style_source', ['feed', 'parse', 'ai', 'manual'])->nullable()->after('occasion');
            $table->jsonb('style_evidence')->nullable()->after('style_source');
            $table->timestamp('style_tagged_at')->nullable()->after('style_evidence');

            $table->index(['category', 'occasion']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['category', 'occasion']);
            $table->dropColumn(['style', 'occasion', 'style_source', 'style_evidence', 'style_tagged_at']);
        });
    }
};

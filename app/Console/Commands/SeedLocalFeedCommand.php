<?php

namespace App\Console\Commands;

use App\Jobs\ImportFeedJob;
use App\Models\Product;
use App\Services\Feed\FeedSource;
use App\Services\Feed\LocalFileFeedSource;
use Illuminate\Console\Command;
use Throwable;

/**
 * DEV-ONLY catalog seeder. The live partner feed is still in moderation, so this
 * fills a development database with realistic products from a local fixture —
 * giving the Phase 2 catalog endpoint and front-end something to show without a
 * partner account or any network call.
 *
 * It does NOT reimplement importing: it swaps the FeedSource binding to read the
 * bundled XML fixture from disk, then runs the REAL ImportFeedJob synchronously,
 * so the bytes go through the exact production path (XmlFeedParser → brand filter
 * → upsert). Idempotent by construction — the upsert keys on external_id, so
 * re-running updates in place instead of creating duplicates.
 *
 * Refuses to run in production. Like r2:check this is an operator tool, not a test.
 *
 * Usage:
 *   php artisan feed:seed-local
 *   php artisan feed:seed-local --file=/path/to/other_feed.xml
 */
class SeedLocalFeedCommand extends Command
{
    protected $signature = 'feed:seed-local {--file= : Path to an XML feed file (defaults to the bundled local catalog fixture)}';

    protected $description = 'Seed the dev catalog from a local XML feed fixture through the real import pipeline (dev only)';

    /**
     * Default fixture shared with tests/Feature/LocalCatalogFeedTest.php — ONE
     * feed, two consumers, so the seeder and the test never drift apart.
     */
    public const DEFAULT_FIXTURE = 'database/seeders/fixtures/local_catalog_feed.xml';

    public function handle(): int
    {
        // Hard dev-only gate: a local fixture must never overwrite a real catalog.
        if ($this->getLaravel()->isProduction()) {
            $this->error('feed:seed-local is a development tool and refuses to run in production.');

            return self::FAILURE;
        }

        $path = $this->option('file') ?: base_path(self::DEFAULT_FIXTURE);

        if (! is_file($path)) {
            $this->error("Feed fixture not found: {$path}");

            return self::FAILURE;
        }

        // Drive the real import path over the local file: rebind the source to the
        // file (this process only — prod stays HttpFeedSource) and force the XML
        // parser regardless of the local FEED_FORMAT.
        $this->getLaravel()->instance(FeedSource::class, new LocalFileFeedSource($path));
        config(['feed.format' => 'xml']);

        $before = Product::count();

        $this->line('Seeding dev catalog from <comment>'.$path.'</comment> via the real ImportFeedJob ...');

        try {
            ImportFeedJob::dispatchSync();
        } catch (Throwable $e) {
            $this->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $after = Product::count();

        $this->info(sprintf(
            'Done. Catalog now holds %d product(s) (%d new this run; the rest updated in place).',
            $after,
            max(0, $after - $before),
        ));
        $this->line('Note: flat_bg_verified stays false for fixture data — only a live feed and a real verifier can decide it.');
        $this->line('To show a product in the catalog, look at its photo and confirm it: <comment>php artisan catalog:verify EXTERNAL-ID ...</comment>');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\ImportFeedJob;
use App\Models\Product;
use App\Services\Feed\FeedSource;
use App\Services\Feed\LocalFileFeedSource;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use SimpleXMLElement;
use Throwable;

/**
 * Operator tool: import a curated XML catalog file into ANY environment,
 * production included.
 *
 * The partner network is still in moderation, so there is no live FEED_URL to
 * import from and the catalog is curated by hand. Before this command the only
 * importer that read a file was feed:seed-local, which refuses to run in
 * production by design — so live products had to be inserted straight into the
 * database, and the live catalog drifted away from git. This is the supported
 * way to close that gap: the products live in a versioned XML file
 * (database/seeders/fixtures/curated_catalog_feed.xml) and get published from
 * it, so the repository and the live catalog say the same thing.
 *
 * It reimplements nothing: it swaps the FeedSource binding to the file and runs
 * the REAL ImportFeedJob synchronously, so the bytes take the exact production
 * path (parser to brand filter to upsert). The upsert keys on external_id, so
 * re-running updates the listed products in place — and never touches a
 * product that is not in the file.
 *
 * Two feed shapes are understood, told apart by the file's root element:
 * Google Merchant RSS (<rss>, XmlFeedParser) and the Amazon Product
 * Advertising API response (<ItemLookupResponse>, AmazonPaApiFeedParser) the
 * Amazon.ae affiliate catalog ships as.
 *
 * Two things it deliberately does NOT do:
 *   - publish anything. Import always writes flat_bg_verified through the
 *     verifier, which is still a stub returning false, so a NEW product lands
 *     out of the catalog until a human looks at its photo and runs
 *     catalog:verify. Re-importing a product that was already published
 *     therefore un-publishes it; the summary below says so out loud.
 *   - let a brand in that FEED_BRANDS does not list. A missing brand is
 *     filtered silently by the job, which looks exactly like a broken feed, so
 *     the command warns up front when the file names a brand the config drops.
 *     --all-brands lifts the list for this run only: a file assembled by hand
 *     is already curated, and a marketplace file names too many brands to
 *     list.
 *
 * Usage:
 *   php artisan catalog:import-file                          # curated catalog
 *   php artisan catalog:import-file --file=/path/to/feed.xml
 *   php artisan catalog:import-file --force                  # no prod prompt
 */
class ImportCatalogFileCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'catalog:import-file
        {--file= : Path to an XML feed file (defaults to the curated catalog)}
        {--all-brands : Let every brand in the file through, ignoring FEED_BRANDS for this run}
        {--force : Skip the production confirmation prompt}';

    protected $description = 'Import a curated XML catalog file through the real import pipeline (works in production)';

    /**
     * The hand-curated catalog shipped in git — the products the live site
     * sells while the partner feed is in moderation.
     */
    public const CURATED_CATALOG = 'database/seeders/fixtures/curated_catalog_feed.xml';

    /**
     * Root element → FEED_FORMAT value: the way a file tells us its shape.
     */
    private const FORMAT_BY_ROOT = [
        'rss' => 'xml',
        'ItemLookupResponse' => 'amazon',
        'ItemSearchResponse' => 'amazon',
    ];

    public function handle(): int
    {
        $allBrands = (bool) $this->option('all-brands');

        $path = $this->option('file')
            ?: base_path(self::CURATED_CATALOG);

        if (! is_file($path)) {
            $this->error("Catalog file not found: {$path}");

            return self::FAILURE;
        }

        $xml = @simplexml_load_file($path);
        $format = $this->detectFormat($xml);

        $this->line("Importing <comment>{$path}</comment> ({$format} format) via the real ImportFeedJob ...");

        if ($allBrands) {
            $this->line('Brand allow-list lifted for this run (--all-brands): every brand in the file is imported.');
        } else {
            $this->warnAboutFilteredBrands($xml);
        }

        // Writing to a live catalog is a real decision — the standard Laravel
        // prompt (skipped by --force and outside production).
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        // Drive the real import path over the local file: rebind the source to
        // the file (this process only — the scheduled import stays on
        // HttpFeedSource) and pick the parser from the file itself, regardless
        // of FEED_FORMAT, which is empty wherever there is no live partner feed
        // yet. The lifted allow-list is likewise this process only.
        $this->getLaravel()->instance(FeedSource::class, new LocalFileFeedSource($path));
        config(['feed.format' => $format]);

        if ($allBrands) {
            config(['feed.brands' => [ImportFeedJob::ANY_BRAND]]);
        }

        $before = Product::count();

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
        $this->line('Imported products are NOT published: import writes flat_bg_verified = false.');
        $this->line('Look at each photo, then publish it: <comment>php artisan catalog:verify EXTERNAL-ID ...</comment>');

        return self::SUCCESS;
    }

    /**
     * The job filters unknown brands silently, which is indistinguishable from
     * a broken file. Read the brands out of the XML first and name the ones
     * FEED_BRANDS will drop, so a misconfigured allow-list is not diagnosed as
     * a parser bug.
     */
    /**
     * Google Merchant RSS unless the root says Amazon. An unreadable file
     * defaults to the RSS parser, which then reports it in its own words.
     */
    private function detectFormat(SimpleXMLElement|false $xml): string
    {
        if ($xml === false) {
            return 'xml';
        }

        return self::FORMAT_BY_ROOT[$xml->getName()] ?? 'xml';
    }

    private function warnAboutFilteredBrands(SimpleXMLElement|false $xml): void
    {
        if ($xml === false) {
            return; // Let the parser report the malformed file in its own words.
        }

        $allowed = collect((array) config('feed.brands', []))
            ->map(fn ($brand) => mb_strtolower(trim((string) $brand)));

        // Matched on the LOCAL name, the same way the parsers read items: a
        // feed carries <brand> or <g:brand> depending on the network, and the
        // Amazon shape spells it <Brand>.
        $filtered = collect($xml->xpath('//*[local-name()="brand" or local-name()="Brand"]') ?: [])
            ->map(fn ($brand) => trim((string) $brand))
            ->filter()
            ->unique()
            ->reject(fn ($brand) => $allowed->contains(mb_strtolower($brand)))
            ->values();

        foreach ($filtered as $brand) {
            $this->warn("Brand not in FEED_BRANDS, its products will be filtered out: {$brand}");
        }
    }
}

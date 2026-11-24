<?php

namespace App\Console\Commands;

use App\Jobs\MeasureProductColor;
use App\Models\Product;
use App\Models\Season;
use App\Services\Color\GarmentPaletteMatcher;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Measure the colour of catalog products, and judge them against the palettes.
 *
 * By default it queues only what needs it: products never measured, measured
 * from a photo the feed has since replaced, or whose last attempt failed
 * (a photo that would not download, a service that was off). A product the
 * engine REJECTED is not retried — the same photo gets the same answer.
 *
 *   php artisan catalog:measure-colors             # what needs it (scheduled daily)
 *   php artisan catalog:measure-colors --all       # every product again
 *   php artisan catalog:measure-colors --rematch   # palettes changed: re-judge, no photos
 *
 * --rematch runs here, synchronously: it is arithmetic over stored colours and
 * the palettes, no photo and no service involved.
 */
class MeasureCatalogColorsCommand extends Command
{
    protected $signature = 'catalog:measure-colors
        {--all : Measure every product again, not only those that need it}
        {--rematch : Re-judge stored colours against the current palettes instead of measuring}';

    protected $description = 'Measure catalog product colours and match them to the season palettes';

    public function handle(GarmentPaletteMatcher $matcher): int
    {
        if ($this->option('rematch')) {
            $seasons = Season::with('colors')->get();
            $count = 0;

            Product::query()->where('color_status', Product::COLOR_OK)->lazyById()
                ->each(function (Product $product) use ($matcher, $seasons, &$count) {
                    $matcher->match($product, $seasons);
                    $count++;
                });

            $this->info("{$count} product(s) re-matched against the palettes.");

            return self::SUCCESS;
        }

        $query = Product::query()->when(! $this->option('all'), fn (Builder $q) => $q->where(
            fn (Builder $q) => $q->whereNull('color_status')
                ->orWhere('color_status', Product::COLOR_FAILED)
                ->orWhereRaw("color->>'image_url' IS DISTINCT FROM image_flat_url")
        ));

        $count = 0;
        $query->lazyById()->each(function (Product $product) use (&$count) {
            MeasureProductColor::dispatch($product)
                ->onConnection((string) config('color_analysis.queue_connection'))
                ->onQueue((string) config('color_analysis.queue'));
            $count++;
        });

        $this->info("{$count} product(s) queued for colour measurement.");

        return self::SUCCESS;
    }
}

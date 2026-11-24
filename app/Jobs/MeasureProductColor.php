<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Color\ColorAnalysisClient;
use App\Services\Color\ColorAnalysisRefused;
use App\Services\Color\ColorAnalysisUnavailable;
use App\Services\Color\GarmentPaletteMatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Measure what colour a catalog product is, then judge it against every
 * season's palette.
 *
 * The photo is the product's own image_flat_url: read from public/ when it is
 * one of ours (the dev catalog), fetched over HTTP when it is the shop's. It
 * goes to pose-service's /garment-colour and the answer is kept whole on the
 * product, with the URL it was measured from — a feed that swaps the photo
 * leaves the product looking unmeasured to catalog:measure-colors.
 *
 * The outcomes, and what each leaves on the product:
 *   - ok        the colours, and a match row per season;
 *   - rejected  the engine looked and could not measure (a white dress on
 *               white): no colours, no matches, and no retry;
 *   - failed    the photo could not be fetched, or the service said no: the
 *               same, and the command picks it up again next run.
 * A service that is DOWN is the one thing retried here, like the face job.
 */
class MeasureProductColor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public Product $product) {}

    public function handle(ColorAnalysisClient $client, GarmentPaletteMatcher $matcher): void
    {
        $url = (string) $this->product->image_flat_url;
        $image = $this->photo($url);

        if ($image === null) {
            $this->record(Product::COLOR_FAILED, ['error' => 'The product photo could not be fetched.'], $url);
            $matcher->match($this->product);

            return;
        }

        try {
            $result = $client->analyzeGarment($image, basename(parse_url($url, PHP_URL_PATH) ?: 'product.jpg'));
        } catch (ColorAnalysisUnavailable $e) {
            throw $e;
        } catch (ColorAnalysisRefused $e) {
            $this->record(Product::COLOR_FAILED, ['error' => $e->getMessage()], $url);
            $matcher->match($this->product);

            return;
        }

        $status = ($result['status'] ?? null) === 'ok' ? Product::COLOR_OK : Product::COLOR_REJECTED;
        $this->record($status, $result, $url);
        $matcher->match($this->product);

        Log::info('[color] product measured', [
            'product_id' => $this->product->id,
            'status' => $status,
            'reject_reason' => $result['reject_reason'] ?? null,
        ]);
    }

    /**
     * The retries ran out on a service that stayed down.
     */
    public function failed(?Throwable $e): void
    {
        $this->record(
            Product::COLOR_FAILED,
            ['error' => 'Colour service unavailable.'],
            (string) $this->product->image_flat_url,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(string $status, array $payload, string $url): void
    {
        $this->product->forceFill([
            'color' => $payload + ['image_url' => $url],
            'color_status' => $status,
            'color_measured_at' => now(),
        ])->save();
    }

    private function photo(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        // Our own files — a relative path, or an absolute URL on this app's own
        // host — straight off the disk. A request to ourselves would go to
        // whatever "localhost" means inside the worker's container, which is
        // not the web server.
        $host = parse_url($url, PHP_URL_HOST);
        if ($host !== null && $host === parse_url((string) config('app.url'), PHP_URL_HOST)) {
            $url = (string) parse_url($url, PHP_URL_PATH);
        }

        if (str_starts_with($url, '/')) {
            // realpath() inside public/ only: a feed URL with ../ in it must
            // not read anything else off the disk.
            $path = realpath(public_path(ltrim(parse_url($url, PHP_URL_PATH) ?: '', '/')));
            if ($path !== false && str_starts_with($path, realpath(public_path()).DIRECTORY_SEPARATOR) && is_file($path)) {
                $contents = file_get_contents($path);

                return $contents === false || $contents === '' ? null : $contents;
            }

            $url = url($url);
        }

        try {
            $response = Http::timeout((int) config('color_analysis.garment_fetch_timeout', 20))->get($url);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() && $response->body() !== '' ? $response->body() : null;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Operator tool: flip products.flat_bg_verified by external_id.
 *
 * flat_bg_verified answers ONE question — is this product photo a clean flat
 * shot the try-on engine can dress, rather than a photo of the garment on a
 * model. Only products where it is true reach GET /api/catalog. The automatic
 * verifier that decides it from the image is still a stub
 * (DefaultFlatBackgroundVerifier returns false), so every imported product
 * lands unverified and the catalog stays empty until a human looks at the
 * photos and says so. This command is that human's voice.
 *
 * It is NOT a way to fill the catalog quickly: verifying a photo that is not a
 * flat shot produces a broken try-on. Run it only for products whose photo you
 * have actually looked at.
 *
 * Usage:
 *   php artisan catalog:verify BOUG-ABAYA-12 MODA-HIJAB-14
 *   php artisan catalog:verify --all            # everything currently imported
 *   php artisan catalog:verify --revoke ZARA-SHIRT-01
 */
class VerifyCatalogPhotosCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'catalog:verify
        {external_id?* : External ids of the products whose photo you have checked}
        {--all : Apply to every product in the catalog}
        {--revoke : Mark the products NOT try-on ready instead (removes them from the catalog)}
        {--force : Skip the production confirmation prompt}';

    protected $description = 'Mark catalog products as try-on ready (flat_bg_verified) after a human has checked their photos';

    public function handle(): int
    {
        $externalIds = (array) $this->argument('external_id');
        $all = (bool) $this->option('all');

        if ($externalIds === [] && ! $all) {
            $this->error('Nothing to do: pass one or more external ids, or --all.');

            return self::FAILURE;
        }

        if ($externalIds !== [] && $all) {
            $this->error('Pass either external ids or --all, not both.');

            return self::FAILURE;
        }

        // Touching the whole catalog in production is a real decision — the
        // standard Laravel prompt (skipped by --force and outside production).
        if ($all && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $verified = ! $this->option('revoke');

        $query = Product::query()
            ->when(! $all, fn ($q) => $q->whereIn('external_id', $externalIds));

        if (! $all) {
            $found = $query->clone()->pluck('external_id')->all();

            foreach (array_diff($externalIds, $found) as $missing) {
                $this->warn("Unknown external_id, skipped: {$missing}");
            }
        }

        $affected = $query->update(['flat_bg_verified' => $verified]);

        $this->info(sprintf(
            '%d product(s) marked %s.',
            $affected,
            $verified ? 'try-on ready (flat_bg_verified = true)' : 'not try-on ready (flat_bg_verified = false)',
        ));

        return self::SUCCESS;
    }
}

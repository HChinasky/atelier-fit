<?php

namespace App\Services\Share;

use App\Models\Generation;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * Composes the branded share cards (Этап B1) from a finished look: a 3:4 feed
 * card and a 9:16 story card, both containing ONLY the generated "after" image
 * — never the visitor's original photo — over the brand plate (wordmark, site,
 * garment, price when known, and the mandatory "AI try-on" marker).
 *
 * Privacy posture is identical to the result itself: the cards are written to
 * the SAME private R2 date partition as the client photo, so the hourly
 * purge (app:purge-expired-photos) reaps them inside the 72h promise, and they
 * are only ever served through short-lived signed URLs minted at read time.
 *
 * Best-effort by contract: composing a card must NEVER fail or delay a
 * generation that already succeeded, so every failure path returns null and
 * logs a note without image content or keys.
 *
 * Assets: the real logo/font land in resources/share/ later (config/share.php
 * points at them); until then the composer degrades to a typographic wordmark
 * and, with no TTF at all, to GD's built-in font — ugly but functional, and
 * swapped by dropping files in, with no code change.
 */
class ShareCardComposer
{
    /**
     * Compose and store both card formats next to the result.
     *
     * @param  string  $resultBytes  The FINAL look's image bytes (already in R2).
     * @param  list<Product>  $products  The garment(s) on the look, wearing order.
     * @return array{feed: string, story: string}|null storage keys, or null when composing failed/disabled
     */
    public function composeAndStore(Generation $generation, string $resultBytes, array $products): ?array
    {
        if (! (bool) config('share.enabled') || $products === []) {
            return null;
        }

        try {
            $manager = new ImageManager(new GdDriver);

            $disk = (string) config('fitting_room.photo.disk');
            $keys = [];

            foreach ((array) config('share.formats') as $format => $size) {
                // Fresh decode per format: resizing mutates the image in place,
                // so feed and story must not share one underlying GD object.
                $look = $manager->read($resultBytes);
                $card = $this->compose($manager, $look, (int) $size['width'], (int) $size['height'], $format, $products);

                $key = sprintf('%s/%s-share-%s.jpg', dirname($generation->client_photo), Str::uuid(), $format);
                Storage::disk($disk)->put($key, (string) $card->toJpeg((int) config('share.quality', 88)), ['visibility' => 'private']);

                $keys[$format] = $key;
            }

            return $keys === [] ? null : $keys;
        } catch (Throwable $e) {
            // Never the exception text (it can echo a signed URL) and never a key.
            Log::warning('[share-card] could not compose share cards', [
                'generation_id' => $generation->id,
            ]);

            return null;
        }
    }

    /**
     * One card: the look filling the area above the plate (feed crops to fill,
     * story letterboxes on the dark surface), the brand plate at the bottom,
     * and a translucent watermark in the image's top-right corner — a spot
     * that stays off the face and body, which sit center-frame.
     *
     * @param  list<Product>  $products
     */
    private function compose(ImageManager $manager, ImageInterface $look, int $width, int $height, string $format, array $products): ImageInterface
    {
        $plateHeight = (int) round($height * ($format === 'story' ? 0.125 : 0.167));
        $imageHeight = $height - $plateHeight;

        // Card ground: white behind the feed image, the brand ink surface
        // behind the story letterbox. Palette colors only.
        $canvas = $manager->create($width, $height)->fill($format === 'story' ? '#2D2D2D' : '#FFFFFF');

        if ($format === 'story') {
            // Story: the look centered whole (contain) on the dark surface.
            $look->scaleDown(width: $width, height: $imageHeight);
            $canvas->place($look, 'top-left', (int) (($width - $look->width()) / 2), (int) (($imageHeight - $look->height()) / 2));
        } else {
            // Feed: the look fills the image area edge to edge (cover crop).
            $look->cover($width, $imageHeight);
            $canvas->place($look, 'top-left', 0, 0);
        }

        $this->watermark($manager, $canvas, $width);
        $this->plate($manager, $canvas, $width, $height, $plateHeight, $products);

        return $canvas;
    }

    /** The translucent corner watermark: the logo file when present, else the monogram. */
    private function watermark(ImageManager $manager, ImageInterface $canvas, int $width): void
    {
        $logoPath = (string) config('share.assets.logo');

        if (is_file($logoPath)) {
            $logo = $manager->read($logoPath)->scaleDown(width: (int) ($width * 0.14));
            $canvas->place($logo, 'top-right', 36, 36, 45);

            return;
        }

        $this->text($canvas, (string) config('share.watermark_text'), $width - 44, 64, 44, 'rgba(255, 255, 255, 0.45)', 'right');
    }

    /**
     * The bottom plate: wordmark + site on the left, garment/brand/price on
     * the right, the AI marker in the corner.
     *
     * @param  list<Product>  $products
     */
    private function plate(ImageManager $manager, ImageInterface $canvas, int $width, int $height, int $plateHeight, array $products): void
    {
        $plate = $manager->create($width, $plateHeight)->fill('#FFFFFF');
        $canvas->place($plate, 'top-left', 0, $height - $plateHeight);

        $pad = (int) round($width * 0.052);
        $top = $height - $plateHeight;

        // Brand block (left): logo image when provided, else the wordmark.
        $logoPath = (string) config('share.assets.logo');
        if (is_file($logoPath)) {
            $logo = $manager->read($logoPath)->scaleDown(height: (int) ($plateHeight * 0.30));
            $canvas->place($logo, 'top-left', $pad, $top + (int) ($plateHeight * 0.18));
        } else {
            $this->text($canvas, mb_strtoupper((string) config('share.brand_name')), $pad, $top + (int) ($plateHeight * 0.34), 34, '#2D2D2D');
        }

        $this->text($canvas, (string) config('share.site_label'), $pad, $top + (int) ($plateHeight * 0.60), 24, '#6B6B6B');

        // Garment block (right-aligned).
        $right = $width - $pad;
        $this->text($canvas, $this->garmentLine($products), $right, $top + (int) ($plateHeight * 0.34), 26, '#2D2D2D', 'right');

        $price = $this->priceLine($products);
        if ($price !== null) {
            $this->text($canvas, $price, $right, $top + (int) ($plateHeight * 0.60), 24, '#6B6B6B', 'right');
        }

        // Honesty marker — small but readable, promised by the Terms/AI disclosure.
        $this->text($canvas, (string) config('share.ai_label'), $right, $height - (int) ($plateHeight * 0.12), 18, '#6B6B6B', 'right');
    }

    /** "Brand — Garment" for a single look; a neutral set label for an outfit. */
    private function garmentLine(array $products): string
    {
        if (count($products) > 1) {
            return sprintf('Outfit · %d items', count($products));
        }

        $product = $products[0];

        return Str::limit(trim($product->brand_name.' — '.$product->product_name, ' —'), 48);
    }

    /** The catalog price, or null (spec: no price → no line). Outfits show none. */
    private function priceLine(array $products): ?string
    {
        if (count($products) !== 1 || $products[0]->price_aed === null) {
            return null;
        }

        return number_format((float) $products[0]->price_aed, 0, '.', ' ').' AED';
    }

    /**
     * Draw a text line using the configured TTF when it exists; without one,
     * GD's built-in font renders a rough placeholder (size is then ignored by
     * GD — accepted until the brand font lands in resources/share/fonts).
     */
    private function text(ImageInterface $canvas, string $string, int $x, int $y, int $size, string $color, string $align = 'left'): void
    {
        $fontPath = (string) config('share.assets.font');

        $canvas->text($string, $x, $y, function ($font) use ($fontPath, $size, $color, $align) {
            if (is_file($fontPath)) {
                $font->filename($fontPath);
            }
            $font->size($size);
            $font->color($color);
            $font->align($align);
            $font->valign('middle');
        });
    }
}

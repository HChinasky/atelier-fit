<?php

namespace App\Services\Catalog;

use App\Models\Product;

/**
 * Reads what a piece is FOR — its style and the occasion it dresses — from
 * its own words (config/catalog_style.php), so capsules can be built per
 * occasion.
 *
 * Keyword parsing is a weak source and is kept honest about it:
 *   - the title is read first, then the feed's category, then the
 *     description; the first field naming exactly one occasion decides;
 *   - a garment name ("jeans", "пиджак") counts only in the title: a feed
 *     category is a bucket of pieces, a description names what to pair;
 *   - a field naming two occasions makes the piece universal (versatile),
 *     and a piece nothing is found for is universal too — never a guess;
 *   - the tag records its source ('parse') and the words it rests on, and is
 *     never written over a tag from a stronger source (feed, ai, manual).
 */
class StyleTagger
{
    public const SOURCE = 'parse';

    /**
     * @return array{style: string|null, occasion: string, evidence: array<string, mixed>}
     */
    public function classify(Product $product): array
    {
        $fields = [
            'title' => $product->product_name,
            'feed_category' => $product->feed_category,
            'description' => $product->description(),
        ];

        foreach ($fields as $field => $text) {
            $hits = $this->hits((string) $text, garments: $field === 'title');
            if ($hits === []) {
                continue;
            }

            $keywords = array_values(array_unique(array_merge(...array_values($hits))));
            $occasions = array_values(array_unique(array_map(
                fn (string $style) => $this->occasionOf($style),
                array_keys($hits),
            )));

            if (count($occasions) > 1) {
                return [
                    'style' => null,
                    'occasion' => Product::OCCASION_UNIVERSAL,
                    'evidence' => ['field' => $field, 'keywords' => $keywords, 'reason' => 'several_occasions'],
                ];
            }

            return [
                'style' => $this->mostSpecific(array_keys($hits)),
                'occasion' => $occasions[0],
                'evidence' => ['field' => $field, 'keywords' => $keywords],
            ];
        }

        return [
            'style' => null,
            'occasion' => Product::OCCASION_UNIVERSAL,
            'evidence' => ['reason' => 'no_keywords'],
        ];
    }

    /**
     * Tag the product unless a stronger source already has. Returns whether
     * anything was written.
     */
    public function tag(Product $product): bool
    {
        if (! $this->mayOverwrite($product->style_source)) {
            return false;
        }

        $tag = $this->classify($product);

        $product->forceFill([
            'style' => $tag['style'],
            'occasion' => $tag['occasion'],
            'style_source' => self::SOURCE,
            'style_evidence' => $tag['evidence'],
        ]);

        if (! $product->isDirty()) {
            return false;
        }

        $product->forceFill(['style_tagged_at' => now()])->save();

        return true;
    }

    /** A parsed tag replaces nothing stronger than itself. */
    public function mayOverwrite(?string $currentSource): bool
    {
        if ($currentSource === null) {
            return true;
        }

        $rank = array_flip(Product::STYLE_SOURCES);

        return ($rank[$currentSource] ?? PHP_INT_MAX) <= $rank[self::SOURCE];
    }

    /**
     * Style → the configured stems found in the text. Garment names count
     * only where they name the piece itself (the title).
     *
     * @return array<string, list<string>>
     */
    private function hits(string $text, bool $garments): array
    {
        $text = str_replace(['ё', 'Ё'], ['е', 'Е'], $text);
        if (trim($text) === '') {
            return [];
        }

        $hits = [];
        foreach ((array) config('catalog_style.keywords') as $style => $words) {
            $stems = [...$words['occasion'], ...($garments ? $words['garment'] : [])];

            foreach ($stems as $stem) {
                $pattern = $this->pattern((string) $stem);
                if (preg_match($pattern, $text)) {
                    $hits[$style][] = (string) $stem;
                }
            }
        }

        return $hits;
    }

    /**
     * A Latin keyword is a whole word (with an optional plural): "prom" is
     * not "promise". A Cyrillic one is a stem matched at the start of a word,
     * for the case endings: "вечерн" is "вечернее" and "вечерний".
     */
    private function pattern(string $stem): string
    {
        $quoted = preg_quote($stem, '/');

        return preg_match('/^[ -~]+$/', $stem)
            ? '/(?<![\p{L}\p{N}])'.$quoted.'(?:e?s)?(?![\p{L}\p{N}])/iu'
            : '/(?<![\p{L}\p{N}])'.$quoted.'/iu';
    }

    private function occasionOf(string $style): string
    {
        return (string) config("catalog_style.occasion_of.{$style}", Product::OCCASION_UNIVERSAL);
    }

    /**
     * @param  list<string>  $styles
     */
    private function mostSpecific(array $styles): string
    {
        foreach ((array) config('catalog_style.style_priority') as $style) {
            if (in_array($style, $styles, true)) {
                return $style;
            }
        }

        return $styles[0];
    }
}

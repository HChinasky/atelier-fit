<?php

namespace App\Services\Feed;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SimpleXMLElement;

/**
 * Concrete FeedParser for Google Merchant RSS 2.0 (the de-facto format served by
 * Admitad MENA / Impact / ArabyAds). Each product is an <item> inside <channel>,
 * with fields under the g: namespace (g:id, g:title, g:price, ...).
 *
 * Real feeds are dirty, so this parser is deliberately tolerant:
 *   - the g: prefix may be missing — every <item> child is flattened to its
 *     LOCAL name, so g:id and a bare <id> resolve the same;
 *   - field names vary between networks — the source name for each target is
 *     configurable (config('feed.fields')), not hardcoded;
 *   - optional fields may be absent — that is not an error.
 *
 * Resilience contract (matches ImportFeedJob): a single broken <item> (missing a
 * required field, an unparseable price, or an unmappable category) is skipped and
 * logged; the rest still import. A wholly malformed feed (XML that will not parse)
 * raises — a loud error, never a silently empty result. No network calls here:
 * the job fetches the raw content and hands it in.
 */
class XmlFeedParser implements FeedParser
{
    /**
     * @return Collection<int, ProductData>
     */
    public function parse(string $rawContent): Collection
    {
        $xml = $this->loadXml($rawContent);
        $namespaces = $xml->getNamespaces(true);

        $products = collect();

        foreach ($xml->channel->item ?? [] as $item) {
            // Two independent reads of the same <item>:
            //   - $fields: the flattened [localName => first non-empty value] map
            //     that feeds the mapping into our typed columns (unchanged);
            //   - $raw: a full structural snapshot kept verbatim for products.raw,
            //     so repeated tags, nesting and attributes survive losslessly.
            $fields = $this->itemToFields($item, $namespaces);
            $raw = $this->snapshotItem($item);

            if ($product = $this->toProductData($fields, $raw)) {
                $products->push($product);
            }
        }

        return $products;
    }

    /**
     * Parse the raw feed into SimpleXML, or fail loudly. libxml errors are
     * captured internally so a broken feed surfaces as a clear RuntimeException
     * (the job logs it and fails for retry) instead of warnings + empty output.
     */
    private function loadXml(string $rawContent): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $xml = simplexml_load_string($rawContent);

        $error = libxml_get_last_error();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            $message = $error ? trim($error->message) : 'unknown libxml error';

            Log::error('[xml-feed-parser] failed to parse feed XML', [
                'error' => $message,
            ]);

            throw new RuntimeException("Malformed feed XML: {$message}");
        }

        return $xml;
    }

    /**
     * Flatten one <item> to a [localName => value] map across every namespace
     * (the no-prefix children and each declared prefix such as g:). The first
     * non-empty value seen for a local name wins, so a feed that carries both a
     * prefixed and an unprefixed copy of a field is handled gracefully.
     *
     * @param  array<string, string>  $namespaces
     * @return array<string, string>
     */
    private function itemToFields(SimpleXMLElement $item, array $namespaces): array
    {
        $fields = [];

        // Children with no namespace prefix (a feed that drops the g: prefix).
        $this->collectChildren($item->children(), $fields);

        // Children under each declared namespace, e.g. g: (the canonical form).
        foreach ($namespaces as $uri) {
            $this->collectChildren($item->children($uri), $fields);
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function collectChildren(?SimpleXMLElement $children, array &$fields): void
    {
        foreach ($children ?? [] as $child) {
            $name = $child->getName();
            $value = trim((string) $child);

            // Keep the first non-empty value for a given local name.
            if (! isset($fields[$name]) || $fields[$name] === '') {
                $fields[$name] = $value;
            }
        }
    }

    /**
     * Take a full structural snapshot of an <item> for products.raw, distinct
     * from the lossy mapping map built by itemToFields(). The shape is the one
     * shared by every XML parser (see XmlSnapshot).
     *
     * @return array<string, mixed>|string
     */
    private function snapshotItem(SimpleXMLElement $item): array|string
    {
        return XmlSnapshot::ofSimpleXml($item);
    }

    /**
     * Map a flattened item to ProductData, or null (skip + log) when it cannot
     * become a valid catalog product: a required field is missing, the price is
     * not a clean amount in the target currency, or the category does not map to
     * one of our three FASHN categories. The full structural snapshot ($raw,
     * built by snapshotItem) is carried through untouched as `raw`.
     *
     * @param  array<string, string>  $fields
     * @param  array<string, mixed>|string  $raw
     */
    private function toProductData(array $fields, array|string $raw): ?ProductData
    {
        $externalId = $this->resolve($fields, 'external_id');
        $brandName = $this->resolve($fields, 'brand_name');
        $productName = $this->resolve($fields, 'product_name');
        $shopUrl = $this->resolve($fields, 'shop_url');
        $imageUrl = $this->resolve($fields, 'image_flat_url');

        foreach (compact('externalId', 'brandName', 'productName', 'shopUrl', 'imageUrl') as $key => $value) {
            if ($value === '') {
                Log::warning('[xml-feed-parser] skipping item: missing required field', [
                    'field' => $key,
                    'external_id' => $externalId,
                ]);

                return null;
            }
        }

        $price = $this->parsePrice($this->resolve($fields, 'price'));
        if ($price === null) {
            Log::warning('[xml-feed-parser] skipping item: unparseable or non-AED price', [
                'external_id' => $externalId,
                'price' => $this->resolve($fields, 'price'),
            ]);

            return null;
        }

        $feedCategory = $this->resolve($fields, 'category');

        $category = $this->mapCategory($feedCategory);
        if ($category === null) {
            Log::warning('[xml-feed-parser] skipping item: category not mappable to a FASHN category', [
                'external_id' => $externalId,
                'category' => $this->resolve($fields, 'category'),
            ]);

            return null;
        }

        return new ProductData(
            externalId: $externalId,
            brandName: $brandName,
            productName: $productName,
            priceAed: $price,
            imageFlatUrl: $imageUrl,
            shopUrl: $shopUrl,
            category: $category,
            raw: (array) $raw,
            feedCategory: $this->feedCategoryLabel($feedCategory),
        );
    }

    /**
     * The feed's own word for the category, as a person would filter by it:
     * the last segment of a Google taxonomy path ("Apparel & Accessories >
     * Clothing > Dresses" → "Dresses"), or the free text as it came.
     */
    private function feedCategoryLabel(string $feedCategory): ?string
    {
        $segments = array_map('trim', explode('>', $feedCategory));
        $last = (string) end($segments);

        return $last === '' ? null : $last;
    }

    /**
     * Resolve a target field to the first non-empty configured source name.
     *
     * @param  array<string, string>  $fields
     */
    private function resolve(array $fields, string $target): string
    {
        foreach ((array) config("feed.fields.{$target}", []) as $sourceName) {
            if (($fields[$sourceName] ?? '') !== '') {
                return $fields[$sourceName];
            }
        }

        return '';
    }

    /**
     * Parse a price string ("149.00 AED", "AED 149.00", "1,499.00 AED") into a
     * decimal string aligned with ProductData / the decimal(10,2) column.
     *
     * Money never touches a float here: the currency is checked as text and the
     * amount is normalized purely by string manipulation. Returns null (→ skip)
     * when the currency is missing/foreign or the amount is not a clean number.
     */
    private function parsePrice(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // The currency token must be present and match the configured currency;
        // a foreign or absent currency means we cannot trust the amount.
        $currency = strtoupper((string) config('feed.currency', 'AED'));
        preg_match_all('/[A-Za-z]+/', $raw, $matches);
        if (strtoupper(implode('', $matches[0])) !== $currency) {
            return null;
        }

        // Strip the currency letters and whitespace, then drop thousands
        // separators (comma). What remains must be a bare decimal number.
        $amount = preg_replace('/[A-Za-z]/', '', $raw);
        $amount = str_replace([',', ' '], '', (string) $amount);

        if (! preg_match('/^\d+(\.\d+)?$/', $amount)) {
            return null;
        }

        return $this->toDecimalString($amount);
    }

    /**
     * Normalize a bare numeric string to exactly two decimal places using string
     * operations only — no float cast, so no rounding drift on the money path.
     */
    private function toDecimalString(string $amount): string
    {
        [$integer, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        $integer = ltrim($integer, '0');
        if ($integer === '') {
            $integer = '0';
        }

        $fraction = substr($fraction.'00', 0, 2);

        return "{$integer}.{$fraction}";
    }

    /**
     * Map a feed category string to one of our FASHN categories via the
     * configured substring table, or null when nothing matches (→ skip, never a
     * silent default). First configured match wins; order is significant.
     */
    private function mapCategory(string $feedCategory): ?string
    {
        $haystack = mb_strtolower(trim($feedCategory));
        if ($haystack === '') {
            return null;
        }

        foreach ((array) config('feed.category_map', []) as $needle => $category) {
            if (str_contains($haystack, mb_strtolower((string) $needle))) {
                return $category;
            }
        }

        return null;
    }
}

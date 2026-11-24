<?php

namespace App\Services\Feed;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Concrete FeedParser for the Amazon Product Advertising API XML shape
 * (ItemLookupResponse / ItemSearchResponse, 2013-08-01): every product is an
 * <Item> under <Items>, identified by <ASIN>, with the affiliate link in
 * <DetailPageURL>, the attributes under <ItemAttributes> and the price in
 * MINOR currency units under <Offers>/.../<Price>/<Amount> (11900 = 119.00).
 *
 * This is the format the Amazon.ae affiliate catalog is handed over in
 * (database/seeders/fixtures/amazon_catalog_feed.xml), so it shares nothing
 * with the Google Merchant RSS that partner networks serve — hence a parser of
 * its own behind the same FeedParser contract, chosen by FEED_FORMAT=amazon or
 * sniffed from the file by catalog:import-file.
 *
 * Namespace tolerance: elements are matched by LOCAL name, so the documented
 * default namespace, a prefixed variant, and the demo:* extension elements the
 * hand-built feed carries all read the same way.
 *
 * What differs from the Google parser, and why:
 *   - a listing without <Brand> is not an error on Amazon: unbranded goods are
 *     sold under the literal brand "Generic". The same fallback is applied here
 *     (config feed.amazon.fallback_brand) instead of dropping the item;
 *   - the price is an integer in minor units plus a separate <CurrencyCode>;
 *     it is converted to the decimal string the column expects with string
 *     arithmetic only, and a foreign currency skips the item;
 *   - Amazon has no garment taxonomy of ours, so the FASHN category is mapped
 *     from the TITLE first (it names the garment: "платье", "жакет", "jeans")
 *     and only then from whatever category-like fields exist (<Category>,
 *     <ProductTypeName>, browse-node names). The title goes first on purpose:
 *     a feed category such as "Костюмы и жакеты" covers both a full-body suit
 *     and an upper-body jacket, and only the title says which one this is.
 *
 * Resilience contract (matches ImportFeedJob and XmlFeedParser): one broken
 * <Item> is skipped and logged, the rest import; malformed XML raises.
 */
class AmazonPaApiFeedParser implements FeedParser
{
    /**
     * @return Collection<int, ProductData>
     */
    public function parse(string $rawContent): Collection
    {
        $xpath = $this->loadXml($rawContent);

        $products = collect();

        foreach ($xpath->query('//*[local-name()="Items"]/*[local-name()="Item"]') ?: [] as $item) {
            if ($product = $this->toProductData($xpath, $item)) {
                $products->push($product);
            }
        }

        return $products;
    }

    /**
     * Parse into a DOM + XPath, or fail loudly: libxml errors are captured so
     * a broken feed surfaces as a clear RuntimeException (the job logs it and
     * fails for retry) instead of warnings plus an empty, "successful" import.
     */
    private function loadXml(string $rawContent): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument;
        $loaded = $rawContent !== '' && $document->loadXML($rawContent);

        $error = libxml_get_last_error();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            $message = $error ? trim($error->message) : 'unknown libxml error';

            Log::error('[amazon-feed-parser] failed to parse feed XML', [
                'error' => $message,
            ]);

            throw new RuntimeException("Malformed feed XML: {$message}");
        }

        return new DOMXPath($document);
    }

    private function toProductData(DOMXPath $xpath, DOMElement $item): ?ProductData
    {
        $asin = $this->text($xpath, $item, 'ASIN');
        $title = $this->text($xpath, $item, 'ItemAttributes/Title');
        $shopUrl = $this->text($xpath, $item, 'DetailPageURL');
        $imageUrl = $this->firstText($xpath, $item, ['LargeImage/URL', 'MediumImage/URL', 'SmallImage/URL']);

        foreach (['ASIN' => $asin, 'Title' => $title, 'DetailPageURL' => $shopUrl, 'LargeImage/URL' => $imageUrl] as $field => $value) {
            if ($value === '') {
                Log::warning('[amazon-feed-parser] skipping item: missing required field', [
                    'field' => $field,
                    'asin' => $asin,
                ]);

                return null;
            }
        }

        $brand = $this->text($xpath, $item, 'ItemAttributes/Brand');
        if ($brand === '') {
            $brand = trim((string) config('feed.amazon.fallback_brand', 'Generic'));
        }
        if ($brand === '') {
            // The fallback is switched off: a brand-less listing is not a product.
            Log::warning('[amazon-feed-parser] skipping item: missing required field', [
                'field' => 'ItemAttributes/Brand',
                'asin' => $asin,
            ]);

            return null;
        }

        $price = $this->parsePrice($xpath, $item);
        if ($price === null) {
            Log::warning('[amazon-feed-parser] skipping item: missing, unparseable or non-AED price', [
                'asin' => $asin,
            ]);

            return null;
        }

        $feedCategories = $this->feedCategories($xpath, $item);

        $category = $this->mapCategory([$title, ...$feedCategories]);
        if ($category === null) {
            Log::warning('[amazon-feed-parser] skipping item: category not mappable to a FASHN category', [
                'asin' => $asin,
                'title' => $title,
            ]);

            return null;
        }

        return new ProductData(
            externalId: $asin,
            brandName: $brand,
            productName: $title,
            priceAed: $price,
            imageFlatUrl: $imageUrl,
            shopUrl: $shopUrl,
            category: $category,
            raw: (array) XmlSnapshot::ofElement($item),
            feedCategory: $feedCategories[0] ?? null,
        );
    }

    /**
     * The price PA-API puts on an item, in order of trust: the live offer, the
     * offer summary, then the list price. Each is an <Amount> in minor units
     * next to a <CurrencyCode>; the first pair that is present decides, and it
     * must be in the configured currency or the item is skipped: a foreign
     * amount stored as AED would be a wrong price on the buy button.
     */
    private function parsePrice(DOMXPath $xpath, DOMElement $item): ?string
    {
        $currency = strtoupper((string) config('feed.currency', 'AED'));

        $candidates = [
            'Offers/Offer/OfferListing/Price',
            'OfferSummary/LowestNewPrice',
            'ItemAttributes/ListPrice',
        ];

        foreach ($candidates as $path) {
            $amount = $this->text($xpath, $item, $path.'/Amount');
            if ($amount === '') {
                continue;
            }

            $code = strtoupper($this->text($xpath, $item, $path.'/CurrencyCode'));
            if ($code !== $currency) {
                return null;
            }

            return $this->minorUnitsToDecimal($amount);
        }

        return null;
    }

    /**
     * "11900" → "119.00", "5" → "0.05". Digits only, no float: money must not
     * pick up rounding drift on the way to a decimal(10,2) column.
     */
    private function minorUnitsToDecimal(string $amount): ?string
    {
        if (! ctype_digit($amount)) {
            return null;
        }

        $padded = str_pad($amount, 3, '0', STR_PAD_LEFT);
        $integer = ltrim(substr($padded, 0, -2), '0');

        return ($integer === '' ? '0' : $integer).'.'.substr($padded, -2);
    }

    /**
     * Every category-like word the item carries, most specific first: the
     * hand-built feed's <Category>, PA-API's <ProductTypeName>, browse-node
     * names. The first one is the feed category the product is filed under;
     * all of them, after the title, are candidates for the FASHN region.
     *
     * @return list<string>
     */
    private function feedCategories(DOMXPath $xpath, DOMElement $item): array
    {
        $candidates = [];

        foreach (['.//*[local-name()="Category"]', './/*[local-name()="ProductTypeName"]', './/*[local-name()="BrowseNode"]/*[local-name()="Name"]'] as $query) {
            foreach ($xpath->query($query, $item) ?: [] as $node) {
                $candidates[] = trim($node->textContent);
            }
        }

        return array_values(array_filter($candidates, fn ($value) => $value !== ''));
    }

    /**
     * Map the first candidate that matches the configured substring table
     * (config feed.category_map: first configured needle wins, so order in the
     * table is significant), or null when nothing matches. The title is the
     * first candidate on purpose (see the class comment). Never a default: a
     * guessed category makes a useless try-on.
     *
     * @param  list<string>  $candidates
     */
    private function mapCategory(array $candidates): ?string
    {
        $map = (array) config('feed.category_map', []);

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $haystack = mb_strtolower($candidate);

            foreach ($map as $needle => $category) {
                if (str_contains($haystack, mb_strtolower((string) $needle))) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * Text of the first element at a slash-separated LOCAL-name path under
     * the item ("ItemAttributes/Title"), or '' when absent.
     */
    private function text(DOMXPath $xpath, DOMElement $item, string $path): string
    {
        $query = implode('/', array_map(
            fn (string $step) => '*[local-name()="'.$step.'"]',
            explode('/', $path),
        ));

        $node = $xpath->query($query, $item)?->item(0);

        return $node ? trim($node->textContent) : '';
    }

    /**
     * @param  list<string>  $paths
     */
    private function firstText(DOMXPath $xpath, DOMElement $item, array $paths): string
    {
        foreach ($paths as $path) {
            $value = $this->text($xpath, $item, $path);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}

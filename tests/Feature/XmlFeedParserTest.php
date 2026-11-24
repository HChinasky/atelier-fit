<?php

use App\Services\Feed\ProductData;
use App\Services\Feed\XmlFeedParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/*
 * Unit-style coverage of the concrete Google Merchant RSS parser. These tests
 * touch no DB and no network — they feed XML strings/fixtures straight in. They
 * live under Feature only so the Laravel app (config(), Log facade) is booted.
 */

function fixture(string $name): string
{
    return file_get_contents(__DIR__.'/../Fixtures/feed/'.$name);
}

function parse(string $xml): Collection
{
    return (new XmlFeedParser)->parse($xml);
}

/** Minimal single-item feed builder for focused assertions. */
function itemFeed(string $itemBody): string
{
    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">
      <channel><item>{$itemBody}</item></channel>
    </rss>
    XML;
}

it('parses a valid Google Merchant feed into ProductData with every field mapped', function () {
    $products = parse(fixture('google_merchant_valid.xml'));

    expect($products)->toHaveCount(3);

    $shirt = $products->firstWhere('externalId', 'ZARA-001');
    expect($shirt)->toBeInstanceOf(ProductData::class)
        ->and($shirt->productName)->toBe('Linen Blend Shirt')
        ->and($shirt->brandName)->toBe('Zara')
        ->and($shirt->shopUrl)->toBe('https://shop.test/zara/001')
        ->and($shirt->imageFlatUrl)->toBe('https://cdn.test/zara/001.jpg')
        ->and($shirt->priceAed)->toBe('149.00')
        ->and($shirt->category)->toBe('upper_body')
        // The feed's own word for the category, kept for the account chips.
        ->and($shirt->feedCategory)->toBe('Shirts & Tops')
        // raw keeps the untouched item map so nothing from the feed is lost
        ->and($shirt->raw['availability'])->toBe('in stock');

    expect($products->firstWhere('externalId', 'MANGO-777')->category)->toBe('lower_body');
    expect($products->firstWhere('externalId', 'ZARA-009')->category)->toBe('dresses');
});

it('keeps a full structural snapshot in raw: repeated tags, nested elements and attributes all survive', function () {
    // One <item> carrying things the mapping path deliberately collapses: two
    // additional image links (same tag repeated) and a nested <details> block
    // whose <param> children differ only by an attribute. The flattened mapping
    // map would keep just the first value per name and drop attributes/nesting;
    // raw must keep everything so any unmapped field stays recoverable.
    $products = parse(itemFeed(<<<'XML'
        <g:id>RICH-1</g:id><g:title>Rich Item</g:title><g:brand>Zara</g:brand>
        <g:link>https://s.test/p</g:link><g:image_link>https://c.test/main.jpg</g:image_link>
        <g:price>149.00 AED</g:price><g:product_type>Shirts</g:product_type>
        <g:additional_image_link>https://c.test/alt-1.jpg</g:additional_image_link>
        <g:additional_image_link>https://c.test/alt-2.jpg</g:additional_image_link>
        <details>
          <param name="size">M</param>
          <param name="color">red</param>
        </details>
    XML));

    expect($products)->toHaveCount(1);
    $raw = $products->first()->raw;

    // A single-valued field is still a plain string (existing raw[...] checks hold).
    expect($raw['title'])->toBe('Rich Item');

    // A repeated tag is preserved as an ordered list — BOTH values, not one.
    expect($raw['additional_image_link'])->toBeArray()
        ->and($raw['additional_image_link'])->toBe([
            'https://c.test/alt-1.jpg',
            'https://c.test/alt-2.jpg',
        ]);

    // Nesting survives, and each repeated child keeps its distinguishing attribute.
    expect($raw['details']['param'])->toBeArray()
        ->and($raw['details']['param'])->toHaveCount(2)
        ->and($raw['details']['param'][0])->toBe(['@name' => 'size', '#text' => 'M'])
        ->and($raw['details']['param'][1])->toBe(['@name' => 'color', '#text' => 'red']);

    // The mapping path is unaffected: the first image still maps to our column.
    expect($products->first()->imageFlatUrl)->toBe('https://c.test/main.jpg')
        ->and($products->first()->category)->toBe('upper_body');
});

it('parses items identically whether or not the g: prefix is present, and follows image fallbacks', function () {
    $products = parse(fixture('format_variants.xml'));

    expect($products)->toHaveCount(3);

    // g:-prefixed item, canonical image_link
    expect($products->firstWhere('externalId', 'PREFIXED-1')->imageFlatUrl)
        ->toBe('https://cdn.test/prefixed-1.jpg');

    // bare (no prefix), image under the "picture" fallback name
    $bare2 = $products->firstWhere('externalId', 'BARE-2');
    expect($bare2->brandName)->toBe('Zara')
        ->and($bare2->imageFlatUrl)->toBe('https://cdn.test/bare-2.jpg')
        ->and($bare2->category)->toBe('upper_body');

    // bare, image under the "photo" fallback name
    expect($products->firstWhere('externalId', 'BARE-3')->imageFlatUrl)
        ->toBe('https://cdn.test/bare-3.jpg');
});

it('parses prices with and without a thousands separator to an exact decimal string', function (string $priceField, string $expected) {
    $products = parse(itemFeed(<<<XML
        <g:id>P-1</g:id><g:title>T</g:title><g:brand>Zara</g:brand>
        <g:link>https://s.test/p</g:link><g:image_link>https://c.test/p.jpg</g:image_link>
        <g:price>{$priceField}</g:price><g:product_type>Shirts</g:product_type>
    XML));

    expect($products)->toHaveCount(1)
        ->and($products->first()->priceAed)->toBe($expected);
})->with([
    'plain' => ['149.00 AED', '149.00'],
    'currency first' => ['AED 149.00', '149.00'],
    'thousands separator' => ['1,499.00 AED', '1499.00'],
    'no decimals' => ['200 AED', '200.00'],
]);

it('skips a product priced in a foreign currency and logs it', function () {
    Log::spy();

    $products = parse(itemFeed(<<<'XML'
        <g:id>FX-1</g:id><g:title>T</g:title><g:brand>Zara</g:brand>
        <g:link>https://s.test/p</g:link><g:image_link>https://c.test/p.jpg</g:image_link>
        <g:price>99.00 USD</g:price><g:product_type>Shirts</g:product_type>
    XML));

    expect($products)->toHaveCount(0);
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('skips a product whose price cannot be parsed and logs it', function () {
    Log::spy();

    $products = parse(itemFeed(<<<'XML'
        <g:id>BADPRICE-1</g:id><g:title>T</g:title><g:brand>Zara</g:brand>
        <g:link>https://s.test/p</g:link><g:image_link>https://c.test/p.jpg</g:image_link>
        <g:price>call us AED</g:price><g:product_type>Shirts</g:product_type>
    XML));

    expect($products)->toHaveCount(0);
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('maps a known feed category to our enum', function (string $feedCategory, string $expected) {
    $products = parse(itemFeed(<<<XML
        <g:id>C-1</g:id><g:title>T</g:title><g:brand>Zara</g:brand>
        <g:link>https://s.test/p</g:link><g:image_link>https://c.test/p.jpg</g:image_link>
        <g:price>50.00 AED</g:price>
        <g:google_product_category>{$feedCategory}</g:google_product_category>
    XML));

    expect($products->first()->category)->toBe($expected);
})->with([
    'google dresses' => ['Apparel &amp; Accessories &gt; Clothing &gt; Dresses', 'dresses'],
    'google skirts' => ['Apparel &amp; Accessories &gt; Clothing &gt; Skirts', 'lower_body'],
    'free text top' => ['Tops', 'upper_body'],
    'shirt dress' => ['Shirt Dresses', 'dresses'], // dresses checked before shirts
    // Modest wear, mapped onto the engine's three body regions: full-body
    // garments to dresses, head and shoulder coverings to upper_body. Khimar
    // and niqab have no product in the fixture (no garment-only photo exists
    // for either), so this dataset is what keeps their mapping covered.
    'abaya' => ['Apparel &amp; Accessories &gt; Clothing &gt; Abayas', 'dresses'],
    'khimar' => ['Modest Wear &gt; Khimars', 'dresses'],
    'hijab' => ['Modest Wear &gt; Hijabs', 'upper_body'],
    'shayla' => ['Modest Wear &gt; Shaylas', 'upper_body'],
    'niqab' => ['Modest Wear &gt; Niqabs', 'upper_body'],
    'abaya dress' => ['Abaya Dresses', 'dresses'], // modest block runs before the generic one
    // Capsule-only categories: shoes and hats. Head coverings stay garments.
    'google shoes' => ['Apparel &amp; Accessories &gt; Shoes', 'shoes'],
    'dress shoes' => ['Dress Shoes', 'shoes'], // shoes checked before dresses
    'ankle boots' => ['Ankle Boots', 'shoes'],
    'bootcut jeans' => ['Bootcut Jeans', 'lower_body'], // "boot" is the last needle
    'russian sneakers' => ['Кроссовки', 'shoes'],
    'ukrainian sandals' => ['Босоніжки', 'shoes'],
    'google hats' => ['Apparel &amp; Accessories &gt; Clothing Accessories &gt; Hats', 'headwear'],
    'russian hat' => ['Шляпа', 'headwear'],
    'hijab cap stays a hijab' => ['Hijab Caps', 'upper_body'],
]);

it('skips a product whose category maps to nothing, without substituting a default, and logs a warning', function () {
    Log::spy();

    $products = parse(itemFeed(<<<'XML'
        <g:id>NOCAT-1</g:id><g:title>Handbag</g:title><g:brand>Zara</g:brand>
        <g:link>https://s.test/p</g:link><g:image_link>https://c.test/p.jpg</g:image_link>
        <g:price>50.00 AED</g:price>
        <g:google_product_category>Apparel &amp; Accessories &gt; Handbags</g:google_product_category>
    XML));

    expect($products)->toHaveCount(0);
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('does not crash when an optional field is absent', function () {
    // No availability / sale_price / gtin etc. — only the required fields.
    $products = parse(itemFeed(<<<'XML'
        <g:id>MIN-1</g:id><g:title>Plain Tee</g:title><g:brand>Zara</g:brand>
        <g:link>https://s.test/p</g:link><g:image_link>https://c.test/p.jpg</g:image_link>
        <g:price>49.00 AED</g:price><g:product_type>T-Shirts</g:product_type>
    XML));

    expect($products)->toHaveCount(1)
        ->and($products->first()->externalId)->toBe('MIN-1');
});

it('skips an item missing a required field but keeps the rest', function () {
    Log::spy();

    $products = parse(<<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">
      <channel>
        <item>
          <g:title>No id here</g:title><g:brand>Zara</g:brand>
          <g:link>https://s.test/p</g:link><g:image_link>https://c.test/p.jpg</g:image_link>
          <g:price>49.00 AED</g:price><g:product_type>Shirts</g:product_type>
        </item>
        <item>
          <g:id>GOOD-1</g:id><g:title>Good</g:title><g:brand>Zara</g:brand>
          <g:link>https://s.test/p</g:link><g:image_link>https://c.test/p.jpg</g:image_link>
          <g:price>49.00 AED</g:price><g:product_type>Shirts</g:product_type>
        </item>
      </channel>
    </rss>
    XML);

    expect($products)->toHaveCount(1)
        ->and($products->first()->externalId)->toBe('GOOD-1');
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('raises on wholly malformed XML instead of returning an empty result', function () {
    Log::spy();

    expect(fn () => parse('this is <not> valid <xml'))
        ->toThrow(RuntimeException::class);

    Log::shouldHaveReceived('error')->atLeast()->once();
});

it('keeps the last segment of a Google taxonomy path as the feed category', function () {
    $product = parse(itemFeed(<<<'XML'
        <g:id>TAX-1</g:id><g:title>Wrap Dress</g:title><g:brand>Zara</g:brand>
        <g:link>https://shop.test/1</g:link><g:image_link>https://cdn.test/1.jpg</g:image_link>
        <g:price>149.00 AED</g:price>
        <g:google_product_category>Apparel &amp; Accessories &gt; Clothing &gt; Dresses</g:google_product_category>
    XML))->first();

    expect($product->category)->toBe('dresses')
        ->and($product->feedCategory)->toBe('Dresses');
});

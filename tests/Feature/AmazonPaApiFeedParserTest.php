<?php

use App\Services\Feed\AmazonPaApiFeedParser;
use App\Services\Feed\ProductData;
use Illuminate\Support\Facades\Log;

/*
 * AmazonPaApiFeedParser reads the Product Advertising API shape the Amazon.ae
 * affiliate catalog is delivered in. The rules under test are the ones that
 * differ from the Google Merchant parser: the ASIN as id, the price as minor
 * units next to a currency code, the "Generic" fallback for a brand-less
 * listing, and the FASHN category read from the TITLE before the feed's own
 * (too coarse) category. The resilience contract is the same as XmlFeedParser:
 * a bad item is skipped and logged, a malformed document raises.
 */

function amazonFixture(string $name = 'amazon_pa_api.xml'): string
{
    return file_get_contents(__DIR__.'/../Fixtures/feed/'.$name);
}

/** @return array<string, ProductData> keyed by ASIN */
function parseAmazon(string $xml): array
{
    return (new AmazonPaApiFeedParser)->parse($xml)
        ->keyBy(fn (ProductData $p) => $p->externalId)
        ->all();
}

it('maps a complete item onto ProductData: ASIN, affiliate link, title, brand, image', function () {
    $product = parseAmazon(amazonFixture())['AMZ-OK'];

    expect($product->externalId)->toBe('AMZ-OK')
        ->and($product->brandName)->toBe('GORGLITTER')
        ->and($product->productName)->toBe('Облегающее платье макси с вырезом лодочкой')
        ->and($product->shopUrl)->toBe('https://www.amazon.ae/dp/AMZ-OK?tag=example-21')
        ->and($product->imageFlatUrl)->toBe('https://cdn.test/amazon/AMZ-OK.jpg')
        ->and($product->category)->toBe('dresses')
        // The feed's own word for the category, kept for the account chips.
        ->and($product->feedCategory)->toBe('Платья');
});

it('leaves the feed category empty when the item carries none', function () {
    expect(parseAmazon(amazonFixture())['AMZ-LISTPRC']->feedCategory)->toBeNull();
});

it('converts the minor-unit Amount into the decimal string the column expects', function () {
    $products = parseAmazon(amazonFixture());

    expect($products['AMZ-OK']->priceAed)->toBe('119.00')
        // A five-fils list price: no float, no lost leading zero.
        ->and($products['AMZ-LISTPRC']->priceAed)->toBe('0.05');
});

it('falls back to the list price and the medium image when the item has no offer or large image', function () {
    $product = parseAmazon(amazonFixture())['AMZ-LISTPRC'];

    expect($product->priceAed)->toBe('0.05')
        ->and($product->imageFlatUrl)->toBe('https://cdn.test/amazon/AMZ-LISTPRC-medium.jpg');
});

it('imports a brand-less listing under the configured fallback brand, the way Amazon itself does', function () {
    expect(parseAmazon(amazonFixture())['AMZ-NOBRAND']->brandName)->toBe('Generic');

    config(['feed.amazon.fallback_brand' => 'Amazon Basics']);
    expect(parseAmazon(amazonFixture())['AMZ-NOBRAND']->brandName)->toBe('Amazon Basics');
});

it('skips a brand-less listing when the fallback brand is switched off', function () {
    config(['feed.amazon.fallback_brand' => '']);

    expect(parseAmazon(amazonFixture()))->not->toHaveKey('AMZ-NOBRAND');
});

it('reads the FASHN category from the title before the feed category', function () {
    $products = parseAmazon(amazonFixture());

    // "Костюмы и жакеты" would be a suit (full body); the title says jacket.
    expect($products['AMZ-JACKET']->category)->toBe('upper_body')
        // A matching set dresses the whole body: the set word wins over "топ".
        ->and($products['AMZ-NOBRAND']->category)->toBe('dresses')
        ->and($products['AMZ-LISTPRC']->category)->toBe('lower_body');
});

it('skips a foreign-currency price, a missing image and an unmappable garment, and keeps the rest', function () {
    Log::spy();

    $products = parseAmazon(amazonFixture());

    expect(array_keys($products))->toBe(['AMZ-OK', 'AMZ-NOBRAND', 'AMZ-LISTPRC', 'AMZ-JACKET']);

    Log::shouldHaveReceived('warning')
        ->with('[amazon-feed-parser] skipping item: missing, unparseable or non-AED price', ['asin' => 'AMZ-USD'])
        ->once();
    Log::shouldHaveReceived('warning')
        ->with('[amazon-feed-parser] skipping item: missing required field', ['field' => 'LargeImage/URL', 'asin' => 'AMZ-NOIMG'])
        ->once();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => $message === '[amazon-feed-parser] skipping item: category not mappable to a FASHN category'
            && $context['asin'] === 'AMZ-NOCAT')
        ->once();
});

it('keeps a lossless snapshot of the item as raw, including the extension elements', function () {
    $raw = parseAmazon(amazonFixture())['AMZ-OK']->raw;

    expect($raw['ASIN'])->toBe('AMZ-OK')
        ->and($raw['ItemAttributes']['Color'])->toBe('Белый')
        ->and($raw['LargeImage']['Height'])->toBe(['@Units' => 'pixels', '#text' => '1200'])
        ->and($raw['Offers']['Offer']['OfferListing']['Price']['FormattedPrice'])->toBe('AED 119.00')
        ->and($raw['EditorialReviews']['EditorialReview']['Content'])->toBe('Белое платье без рукавов.')
        // demo:* is flattened to its local name, like every other prefix.
        ->and($raw['Metadata']['SourceTitle'])->toBe("GORGLITTER Women's Boat Neck Ruched Bodycon Maxi Dress")
        ->and($raw['Metadata']['Category'])->toBe('Платья');
});

it('reads a prefixed document the same as the default-namespace one', function () {
    $xml = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <a:ItemLookupResponse xmlns:a="http://webservices.amazon.com/AWSECommerceService/2013-08-01">
      <a:Items>
        <a:Item>
          <a:ASIN>PREFIXED</a:ASIN>
          <a:DetailPageURL>https://www.amazon.ae/dp/PREFIXED?tag=example-21</a:DetailPageURL>
          <a:LargeImage><a:URL>https://cdn.test/amazon/PREFIXED.jpg</a:URL></a:LargeImage>
          <a:ItemAttributes><a:Brand>ELLEVEN</a:Brand><a:Title>Linen palazzo trousers</a:Title></a:ItemAttributes>
          <a:Offers><a:Offer><a:OfferListing><a:Price>
            <a:Amount>12900</a:Amount><a:CurrencyCode>AED</a:CurrencyCode>
          </a:Price></a:OfferListing></a:Offer></a:Offers>
        </a:Item>
      </a:Items>
    </a:ItemLookupResponse>
    XML;

    $product = parseAmazon($xml)['PREFIXED'];

    expect($product->priceAed)->toBe('129.00')
        ->and($product->category)->toBe('lower_body');
});

it('raises on malformed XML instead of returning an empty, "successful" result', function () {
    Log::spy();

    expect(fn () => (new AmazonPaApiFeedParser)->parse('<ItemLookupResponse><Items><Item>'))
        ->toThrow(RuntimeException::class, 'Malformed feed XML');

    Log::shouldHaveReceived('error')->once();
});

it('returns nothing for a well-formed document without items', function () {
    expect((new AmazonPaApiFeedParser)->parse('<ItemLookupResponse><Items/></ItemLookupResponse>'))
        ->toBeEmpty();
});

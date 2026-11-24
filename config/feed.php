<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product feed source
    |--------------------------------------------------------------------------
    |
    | Where ImportFeedJob fetches the raw partner feed from, how long to wait,
    | and the expected format (consumed by the concrete parser in a later step).
    | All values come from FEED_* env vars — never read env() in the job/code.
    |
    */

    'url' => env('FEED_URL'),

    // Which concrete parser the container resolves. Intentionally has NO default:
    // when FEED_FORMAT is unset/empty the app falls back to the safe NullFeedParser
    // (imports nothing), so a fresh, unconfigured environment never runs a real
    // import by accident. Set FEED_FORMAT=xml explicitly to enable XmlFeedParser.
    'format' => env('FEED_FORMAT'),

    'timeout' => (int) env('FEED_TIMEOUT', 30),

    // Optional bearer token for protected feeds.
    'auth_token' => env('FEED_AUTH_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Allowed brands
    |--------------------------------------------------------------------------
    |
    | Only products whose brand is in this list are imported into the catalog.
    | Comma-separated in env; matched case-insensitively in the job.
    |
    */

    'brands' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FEED_BRANDS', 'Zara,Mango,Massimo Dutti,H&M,Bouguessa,Modanisa,OneByOne,Zimmermann'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Target currency
    |--------------------------------------------------------------------------
    |
    | Prices in the feed arrive as strings with a currency token ("149.00 AED").
    | Only this currency is accepted; a product priced in anything else is
    | skipped rather than imported with a wrong amount. The project sells in AED.
    |
    */

    'currency' => env('FEED_CURRENCY', 'AED'),

    /*
    |--------------------------------------------------------------------------
    | Amazon Product Advertising API feeds (FEED_FORMAT=amazon)
    |--------------------------------------------------------------------------
    |
    | An Amazon listing may carry no <Brand> at all; Amazon itself sells such
    | goods under the literal brand "Generic". AmazonPaApiFeedParser applies
    | the same word rather than dropping the item, so a brand-less listing is
    | still a product with a buy button. Set it to '' to skip those instead.
    |
    */

    'amazon' => [
        'fallback_brand' => env('FEED_AMAZON_FALLBACK_BRAND', 'Generic'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Source field names → ProductData fields
    |--------------------------------------------------------------------------
    |
    | Real partner feeds (Admitad MENA / Impact / ArabyAds) are de-facto Google
    | Merchant RSS 2.0, but dirty: a field's local name varies between networks
    | (image under image_link / picture / photo / image), and the g: prefix may
    | be missing. The parser flattens every <item> child to its LOCAL name, then
    | resolves each target to the FIRST non-empty candidate below. Adjust the
    | candidate lists here when a network diverges — never the parser code.
    |
    */

    'fields' => [
        'external_id' => ['id'],
        'product_name' => ['title'],
        'brand_name' => ['brand'],
        'shop_url' => ['link'],
        'image_flat_url' => ['image_link', 'picture', 'photo', 'image'],
        'price' => ['price'],
        // Where the source category lives: Google taxonomy first, then the
        // free-text product_type / category fallbacks.
        'category' => ['google_product_category', 'product_type', 'category'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feed category → FASHN category mapping
    |--------------------------------------------------------------------------
    |
    | The feed's category is Google taxonomy ("Apparel & Accessories > Clothing
    | > Dresses") or free text — NOT our upper_body/lower_body/dresses enum. Each
    | key is a lowercase SUBSTRING matched against the lowercased feed category;
    | the FIRST match wins, so ORDER MATTERS: list the more specific groups
    | first (dresses before tops, so "shirt dress" maps to dresses, not a shirt).
    | A product whose category matches nothing here is skipped — we never guess a
    | default, because a wrong category produces a useless try-on (and the DB
    | CHECK constraint only allows the values below: the three try-on regions,
    | plus shoes and headwear for capsules).
    |
    */

    'category_map' => [
        // Shoes and headwear complete a capsule; they are never offered for
        // try-on (Product::TRY_ON_CATEGORIES). Shoe words go first so "dress
        // shoes" or "туфли к платью" are shoes, not a dress. Only words that
        // cannot be part of a garment's name: "boot" comes last, after the
        // bottoms, so a "bootcut jean" stays a jean.
        'shoe' => 'shoes',
        'sneaker' => 'shoes',
        'trainers' => 'shoes',
        'sandal' => 'shoes',
        'loafer' => 'shoes',
        'stiletto' => 'shoes',
        'espadrille' => 'shoes',
        'туфли' => 'shoes',
        'туфлі' => 'shoes',
        'кроссовки' => 'shoes',
        'кросівки' => 'shoes',
        'кеды' => 'shoes',
        'кеди' => 'shoes',
        'ботинки' => 'shoes',
        'черевики' => 'shoes',
        'сапоги' => 'shoes',
        'чоботи' => 'shoes',
        'ботильоны' => 'shoes',
        'ботильйони' => 'shoes',
        'босоножки' => 'shoes',
        'босоніжки' => 'shoes',
        'сандалии' => 'shoes',
        'сандалі' => 'shoes',
        'лоферы' => 'shoes',
        'лофери' => 'shoes',
        'мюли' => 'shoes',
        'мюлі' => 'shoes',
        'балетки' => 'shoes',

        // Modest wear (the UAE market this catalog sells into). Listed FIRST so a
        // feed category that names the garment explicitly ("Abaya Dress",
        // "Hijab Tops") is read as modest wear rather than falling into the
        // generic group below.
        //
        // The try-on engine knows three body regions and NONE of them is the
        // head, so head coverings are mapped to the nearest region they actually
        // occupy on the body rather than getting a category of their own:
        //   - abaya / khimar cover the whole body (khimar falls to hip or below)
        //     → dresses, the full-body one-piece region;
        //   - hijab / shayla / niqab sit on the head, neck and shoulders
        //     → upper_body, the closest region the engine can dress.
        // This is a deliberate approximation, not a modelling claim: a proper
        // head layer would need a new category in the products CHECK constraint
        // and a new layer in fitting_room.outfit.layer_order.
        'abaya' => 'dresses',
        'khimar' => 'dresses',
        'hijab' => 'upper_body',
        'shayla' => 'upper_body',
        'niqab' => 'upper_body',

        // Headwear: hats and caps only. Head coverings above stay try-on
        // garments; these come after them so a "hijab cap" is still a hijab.
        'hats' => 'headwear',
        'sun hat' => 'headwear',
        'straw hat' => 'headwear',
        'bucket hat' => 'headwear',
        'fedora' => 'headwear',
        'beret' => 'headwear',
        'beanie' => 'headwear',
        'baseball cap' => 'headwear',
        'шляпа' => 'headwear',
        'капелюх' => 'headwear',
        'панама' => 'headwear',
        'кепка' => 'headwear',
        'берет' => 'headwear',

        // Full-body one-pieces → dresses (checked first).
        'dress' => 'dresses',
        'gown' => 'dresses',
        'jumpsuit' => 'dresses',

        // The Amazon.ae catalog is titled in Russian, and its category is too
        // coarse to place a garment ("Костюмы и жакеты" holds both suits and
        // jackets), so AmazonPaApiFeedParser maps from the title. A suit or a
        // matching set (jacket + trousers, top + leggings) dresses the whole
        // body, and of the three regions the engine knows only the one-piece
        // region covers it — the same approximation the abaya gets above. The
        // set words come before the piece words so "комплект: топ и легинсы"
        // reads as a set, not as a top.
        'платье' => 'dresses',
        'сукня' => 'dresses',
        'комбинезон' => 'dresses',
        'комбінезон' => 'dresses',
        'костюм' => 'dresses',
        'комплект' => 'dresses',
        'юбка' => 'lower_body',
        'спідниця' => 'lower_body',
        'брюки' => 'lower_body',
        'штани' => 'lower_body',
        'джинсы' => 'lower_body',
        'джинси' => 'lower_body',
        'легинсы' => 'lower_body',
        'легінси' => 'lower_body',
        'шорты' => 'lower_body',
        'жакет' => 'upper_body',
        'пиджак' => 'upper_body',
        'піджак' => 'upper_body',
        'жилет' => 'upper_body',
        'блуза' => 'upper_body',
        'рубашка' => 'upper_body',
        'сорочка' => 'upper_body',
        'топ' => 'upper_body',
        'свитер' => 'upper_body',
        'светр' => 'upper_body',
        'худи' => 'upper_body',
        'куртка' => 'upper_body',
        'пальто' => 'upper_body',

        // Bottoms.
        'skirt' => 'lower_body',
        'trouser' => 'lower_body',
        'pant' => 'lower_body',
        'jean' => 'lower_body',
        'shorts' => 'lower_body',
        'legging' => 'lower_body',
        'bottom' => 'lower_body',

        // Tops / outerwear.
        'shirt' => 'upper_body',
        'top' => 'upper_body',
        'blouse' => 'upper_body',
        'sweater' => 'upper_body',
        'jumper' => 'upper_body',
        'cardigan' => 'upper_body',
        'hoodie' => 'upper_body',
        'sweatshirt' => 'upper_body',
        'jacket' => 'upper_body',
        'coat' => 'upper_body',

        // Last, see the shoes block.
        'boot' => 'shoes',
    ],

];

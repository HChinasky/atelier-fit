<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Catalog page size
    |--------------------------------------------------------------------------
    |
    | Default number of products per page for GET /api/catalog. A request may
    | override it with ?per_page (bounded by the CatalogIndexRequest rules).
    |
    */

    'per_page' => (int) env('CATALOG_PER_PAGE', 24),

    /*
    |--------------------------------------------------------------------------
    | Show unverified products (DEVELOPMENT ONLY)
    |--------------------------------------------------------------------------
    |
    | Production contract: the catalog returns ONLY products with
    | flat_bg_verified = true. While the photo verifier is still a stub, every
    | imported product is unverified, so a freshly seeded dev catalog would be
    | empty — leaving the front-end nothing to render.
    |
    | When this flag is true AND the app is NOT in production, the catalog also
    | returns unverified products. The extra "not production" guard lives in the
    | controller, so even if this env value is accidentally left enabled in
    | production, unverified products can never leak to real users — the
    | prod-only-verified contract is enforced by the code, not by configuration.
    |
    */

    'show_unverified' => (bool) env('CATALOG_SHOW_UNVERIFIED', false),

    /*
    |--------------------------------------------------------------------------
    | Amazon only
    |--------------------------------------------------------------------------
    |
    | On: everything a visitor can pick, browse or be recommended comes from the
    | Amazon.ae catalog; the curated and local-feed products (Zara, Mango, H&M,
    | OneByOne and the rest) stay in the database but are not shown. Nothing is
    | deleted, so setting this to false brings them back. See
    | Product::scopeFromActiveSources().
    |
    */

    'amazon_only' => (bool) env('CATALOG_AMAZON_ONLY', true),

    /*
    |--------------------------------------------------------------------------
    | Category chips: feed category → label
    |--------------------------------------------------------------------------
    |
    | The account's category chips are built from products.feed_category, the
    | word each FEED files a product under. Feeds speak different languages
    | (the Amazon.ae catalog says "Платья", the curated feed "Dresses") and
    | the interface speaks its own language, so this map sends each feed word
    | to a chip key and lands two feeds' words for one thing on ONE chip. The
    | chip's words are lang/<locale>/catalog.php `categories.<key>`. Keys are
    | matched case-insensitively; the ORDER of the chips is the order here.
    | A feed category missing here is shown as it came, never dropped.
    |
    */

    'category_labels' => [
        'Платья' => 'dresses',
        'Dresses' => 'dresses',
        'Костюмы и жакеты' => 'suits',
        'Топы и блузы' => 'tops',
        'Shirts' => 'tops',
        'Tops' => 'tops',
        'Юбки' => 'skirts',
        'Skirts' => 'skirts',
        'Брюки и джинсы' => 'trousers',
        'Trousers' => 'trousers',
        'Спорт' => 'sport',
    ],

    /*
    |--------------------------------------------------------------------------
    | "Примірка" chips: which feed categories are "Піджаки"
    |--------------------------------------------------------------------------
    |
    | The try-on screen's chips are fixed by the design hand-over (see TryOnChips);
    | the engine category alone tells dresses, tops and bottoms apart, and
    | these feed words (matched case-insensitively) put a piece under
    | "Піджаки" instead.
    |
    */

    'tryon_jacket_categories' => [
        'Костюмы и жакеты',
        'Jackets',
        'Blazers',
        'Coats',
    ],

];

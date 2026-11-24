<?php

use App\Models\Product;
use App\Services\Catalog\StyleTagger;

/*
 * Style / occasion tags parsed from a piece's own words. Parsing is weak, so
 * the tests pin the honest part as much as the matching: no keyword, or two
 * occasions at once, means universal and no style — never a guess.
 */

function classifyStyle(string $title, ?string $description = null, ?string $feedCategory = null): array
{
    $factory = Product::factory()->ofFeedCategory($feedCategory);
    if ($description !== null) {
        $factory = $factory->described($description);
    }

    return app(StyleTagger::class)->classify($factory->make(['product_name' => $title]));
}

it('tags a piece by keywords in its title', function (string $title, string $style, string $occasion) {
    $tag = classifyStyle($title);

    expect($tag['style'])->toBe($style)
        ->and($tag['occasion'])->toBe($occasion)
        ->and($tag['evidence']['field'])->toBe('title');
})->with([
    'english evening' => ['Sequin cocktail dress', 'evening', 'evening'],
    'english office' => ['Tailored office blazer', 'business', 'office'],
    'english formal' => ['Formal wide trousers', 'formal', 'office'],
    'english casual' => ['Relaxed denim jeans', 'casual', 'casual'],
    'english sport' => ['Gym leggings', 'sport', 'casual'],
    'russian evening' => ['Асимметричное вечернее платье макси', 'evening', 'evening'],
    'russian business' => ['Деловой костюм с пиджаком и юбкой', 'business', 'office'],
    'russian sport' => ['Спортивный комплект: топ и легинсы', 'sport', 'casual'],
    'russian casual' => ['Широкие джинсы с низкой посадкой', 'casual', 'casual'],
    'ukrainian evening' => ['Вечірня сукня з паєтками', 'evening', 'evening'],
    'ukrainian office' => ['Діловий піджак', 'business', 'office'],
    'ukrainian casual' => ['Повсякденна футболка', 'casual', 'casual'],
    'russian cap' => ['Бежевая хлопковая кепка', 'casual', 'casual'],
    'russian bucket hat' => ['Бежевая хлопковая панама с широкими полями', 'casual', 'casual'],
]);

it('keeps the most specific style when the words agree on the occasion', function () {
    // sport (спорт) and casual (худи, джоггер) both dress a casual day.
    $tag = classifyStyle('Спортивный костюм с худи и джоггерами');

    expect($tag['style'])->toBe('sport')
        ->and($tag['occasion'])->toBe('casual');
});

it('makes a piece universal when nothing in its words names an occasion', function () {
    $tag = classifyStyle('Атласный топ на одно плечо с баской', 'Однотонная модель с баской.', 'Топы и блузы');

    expect($tag['style'])->toBeNull()
        ->and($tag['occasion'])->toBe(Product::OCCASION_UNIVERSAL)
        ->and($tag['evidence'])->toBe(['reason' => 'no_keywords']);
});

it('makes a piece universal when one field names two occasions', function () {
    $tag = classifyStyle('Blouse for the office or an evening out');

    expect($tag['style'])->toBeNull()
        ->and($tag['occasion'])->toBe(Product::OCCASION_UNIVERSAL)
        ->and($tag['evidence']['reason'])->toBe('several_occasions')
        ->and($tag['evidence']['keywords'])->toContain('office', 'evening');
});

it('reads the title before the feed category and the description', function () {
    // The title says evening; the description's "повседневн" is not consulted.
    expect(classifyStyle('Вечернее платье', 'Подходит для повседневных образов.')['occasion'])->toBe('evening');
});

it('falls back to the feed category, then the description', function () {
    expect(classifyStyle('Комплект: топ и легинсы', null, 'Спорт')['evidence']['field'])->toBe('feed_category')
        ->and(classifyStyle('Чёрный комплект', 'Строгий силуэт для офисных и деловых образов.')['occasion'])->toBe('office');
});

it('reads a garment name only in the title, not in a feed bucket or a pairing tip', function () {
    // "Брюки и джинсы" files palazzo trousers next to jeans: they are not jeans.
    expect(classifyStyle('Льняные брюки палаццо', null, 'Брюки и джинсы')['occasion'])->toBe(Product::OCCASION_UNIVERSAL)
        ->and(classifyStyle('Шёлковая блуза', 'Носите с джинсами или юбкой.')['occasion'])->toBe(Product::OCCASION_UNIVERSAL)
        // Occasion words still count everywhere.
        ->and(classifyStyle('Комбинезон', null, 'Спорт')['occasion'])->toBe('casual');
});

it('matches an English keyword only as a whole word', function () {
    // An English keyword is a whole word: "prom" inside "promise" is not a prom dress.
    expect(classifyStyle('Promise top')['occasion'])->toBe(Product::OCCASION_UNIVERSAL);
});

it('stores the tag with its source and evidence', function () {
    $product = Product::factory()->create(['product_name' => 'Evening gown']);

    expect(app(StyleTagger::class)->tag($product))->toBeTrue();

    $product->refresh();
    expect($product->style)->toBe('evening')
        ->and($product->occasion)->toBe('evening')
        ->and($product->style_source)->toBe('parse')
        ->and($product->style_evidence['keywords'])->toContain('evening', 'gown')
        ->and($product->style_tagged_at)->not->toBeNull();
});

it('never overwrites a tag from a stronger source', function (string $source) {
    $product = Product::factory()->forOccasion('office', 'business', $source)
        ->create(['product_name' => 'Evening gown']);

    expect(app(StyleTagger::class)->tag($product))->toBeFalse()
        ->and($product->refresh()->occasion)->toBe('office')
        ->and($product->style_source)->toBe($source);
})->with(['feed', 'ai', 'manual']);

it('does not rewrite an unchanged parsed tag', function () {
    $product = Product::factory()->create(['product_name' => 'Evening gown']);
    $tagger = app(StyleTagger::class);

    $tagger->tag($product);

    expect($tagger->tag($product->refresh()))->toBeFalse();
});

it('defaults a new product to universal, untagged', function () {
    $product = Product::factory()->create()->refresh();

    expect($product->occasion)->toBe('universal')
        ->and($product->style)->toBeNull()
        ->and($product->style_source)->toBeNull();
});

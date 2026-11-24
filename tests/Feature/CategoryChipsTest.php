<?php

use App\Models\Product;
use App\Services\Catalog\CategoryChips;

/*
 * The account's category chips are built from products.feed_category, worded
 * for the interface through config('catalog.category_labels'). The rules:
 * two feeds' words for one thing land on one chip, an unknown word is shown
 * as it came (never dropped), and only categories that are present appear.
 */

beforeEach(function () {
    config(['catalog.category_labels' => [
        'Платья' => 'Сукні',
        'Dresses' => 'Сукні',
        'Юбки' => 'Спідниці',
        'Спорт' => 'Спорт',
    ]]);
});

it('words a feed category for the interface, case-insensitively', function () {
    expect(CategoryChips::label('Платья'))->toBe('Сукні')
        ->and(CategoryChips::label('dresses'))->toBe('Сукні')
        ->and(CategoryChips::label(' Юбки '))->toBe('Спідниці');
});

it('shows a feed category the map has no word for as it came, and nothing for none', function () {
    expect(CategoryChips::label('Аксессуары'))->toBe('Аксессуары')
        ->and(CategoryChips::label(null))->toBeNull()
        ->and(CategoryChips::label('  '))->toBeNull();
});

it('lists one chip per category present, in the configured order, unmapped words last', function () {
    $products = [
        Product::factory()->make(['feed_category' => 'Юбки']),
        Product::factory()->make(['feed_category' => 'Dresses']),
        Product::factory()->make(['feed_category' => 'Платья']),
        Product::factory()->make(['feed_category' => 'Верхняя одежда']),
        Product::factory()->make(['feed_category' => null]),
        Product::factory()->make(['feed_category' => 'Аксессуары']),
    ];

    expect(CategoryChips::for($products))->toBe(['Сукні', 'Спідниці', 'Аксессуары', 'Верхняя одежда']);
});

it('offers no chip for a category no product is filed under', function () {
    expect(CategoryChips::for([Product::factory()->make(['feed_category' => 'Спорт'])]))->toBe(['Спорт'])
        ->and(CategoryChips::for([]))->toBe([]);
});

it('exposes the chip on the product as category_label', function () {
    expect(Product::factory()->make(['feed_category' => 'Платья'])->category_label)->toBe('Сукні')
        ->and(Product::factory()->make(['feed_category' => null])->category_label)->toBeNull();
});

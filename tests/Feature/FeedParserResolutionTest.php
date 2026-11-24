<?php

use App\Services\Feed\AmazonPaApiFeedParser;
use App\Services\Feed\FeedParser;
use App\Services\Feed\NullFeedParser;
use App\Services\Feed\XmlFeedParser;

/*
 * Contract: the safe NullFeedParser is the ACTUAL default. A real parser is only
 * ever resolved when FEED_FORMAT is explicitly 'xml' (Google Merchant RSS) or
 * 'amazon' (Product Advertising API); an unset, empty, or unknown format must
 * fall back to NullFeedParser so a fresh, unconfigured environment never runs a
 * real import by accident. The binding is not a singleton, so each resolve
 * re-reads config('feed.format').
 */

it('resolves NullFeedParser when the feed format is unset (the safe default)', function () {
    config(['feed.format' => null]);

    expect(app(FeedParser::class))->toBeInstanceOf(NullFeedParser::class);
});

it('resolves NullFeedParser when the feed format is an empty string', function () {
    config(['feed.format' => '']);

    expect(app(FeedParser::class))->toBeInstanceOf(NullFeedParser::class);
});

it('resolves NullFeedParser for an unknown format', function () {
    config(['feed.format' => 'json']);

    expect(app(FeedParser::class))->toBeInstanceOf(NullFeedParser::class);
});

it('resolves XmlFeedParser only when the format is explicitly xml', function () {
    config(['feed.format' => 'xml']);

    expect(app(FeedParser::class))->toBeInstanceOf(XmlFeedParser::class);
});

it('resolves AmazonPaApiFeedParser only when the format is explicitly amazon', function () {
    config(['feed.format' => 'amazon']);

    expect(app(FeedParser::class))->toBeInstanceOf(AmazonPaApiFeedParser::class);
});

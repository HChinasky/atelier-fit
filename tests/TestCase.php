<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Symfony's test requests carry "Accept-Language: en-us" unless told
        // otherwise, which would pick English for every page (SetLocale).
        // Tests read the default language (APP_LOCALE in phpunit.xml); one
        // that needs another asks for it with ?lang= or this header.
        $this->withHeader('Accept-Language', (string) config('app.locale'));
    }
}

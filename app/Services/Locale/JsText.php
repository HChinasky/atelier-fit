<?php

namespace App\Services\Locale;

/**
 * A translated string as a JavaScript string literal, for Alpine expressions
 * and inline scripts: `@jsText(__('dashboard.copied'))`.
 *
 * The same safety as Blade's @js — quotes, apostrophes, <, > and & are
 * escaped, so it sits inside a double-quoted HTML attribute or a <script> —
 * but letters stay letters: @js would write every Cyrillic (or Arabic)
 * character as \uXXXX, leaving the page source unreadable.
 */
final class JsText
{
    public static function from(mixed $text): string
    {
        $json = json_encode(
            (string) $text,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        // A single-quoted literal, as @js writes strings.
        return "'".substr($json, 1, -1)."'";
    }
}

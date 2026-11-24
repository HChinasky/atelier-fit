<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Branded share card (Этап B)
    |--------------------------------------------------------------------------
    |
    | After a successful generation the final look is composed into shareable
    | branded images (feed 3:4 and story 9:16). The cards contain ONLY the
    | "after" image — never the visitor's original photo — and live in the same
    | private R2 prefix as the result, under the same 72-hour purge; they are
    | served exclusively through short-lived signed URLs (≤30 min). No public
    | URL to a card ever exists.
    |
    */

    'enabled' => (bool) env('SHARE_CARDS_ENABLED', true),

    // Signed-URL lifetime for serving a card to its owner. Keep ≤ 30 min — the
    // privacy policy's "short-lived links" wording is anchored to that.
    'signed_url_minutes' => (int) env('SHARE_SIGNED_URL_MINUTES', 30),

    'formats' => [
        'feed' => ['width' => 1080, 'height' => 1440],
        'story' => ['width' => 1080, 'height' => 1920],
    ],

    // JPEG quality of the composed card.
    'quality' => (int) env('SHARE_CARD_QUALITY', 88),

    /*
    | Brand assets. The real logo (PNG with transparency) and a TTF/OTF font
    | are dropped into resources/share/ by the operator; until they exist the
    | composer falls back to typographic placeholders (brand wordmark as text,
    | GD's built-in font when no TTF is present). Paths are configurable so the
    | swap needs no code change.
    */
    'assets' => [
        'logo' => env('SHARE_LOGO_PATH', resource_path('share/logo.png')),
        'font' => env('SHARE_FONT_PATH', resource_path('share/fonts/card.ttf')),
    ],

    // Texts on the plate. The AI marker is a legal-honesty element (the Terms
    // and AI disclosure say every shared card carries it) — do not remove.
    'brand_name' => env('SHARE_BRAND_NAME', 'Atelier Fit'),
    'site_label' => env('SHARE_SITE_LABEL', 'demo.atelierfit.example'),
    'ai_label' => 'AI try-on',

    // Corner watermark when no logo file exists yet.
    'watermark_text' => env('SHARE_WATERMARK_TEXT', 'IR'),

];

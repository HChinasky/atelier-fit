{{-- The framework's error pages (404, 419, 429, 500…) extend errors::minimal;
     this file takes its place so they speak the visitor's language and the
     site's palette. Titles are the framework's JSON keys (lang/uk.json). --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Services\Locale\Locales::direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Atelier Fit — @yield('title')</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="stylesheet" href="{{ asset('fonts/fonts.css') }}">
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #FFFFFF; color: #2D2D2D; font-family: 'Montserrat', sans-serif; text-align: center; padding: 24px; }
        .err-code { font-family: 'Playfair Display', serif; font-size: 64px; line-height: 1; margin: 0 0 16px; }
        .err-message { font-size: 15px; color: #6B6B6B; margin: 0 0 32px; }
        .err-home { display: inline-block; padding: 14px 30px; background: #2D2D2D; color: #FFFFFF;
            font-size: 11px; letter-spacing: .16em; text-transform: uppercase; text-decoration: none; }
    </style>
</head>
<body>
    <main id="error-page">
        <p class="err-code">@yield('code')</p>
        <p class="err-message">@yield('message')</p>
        <a class="err-home" href="{{ url('/') }}">{{ __('errors.home') }}</a>
    </main>
</body>
</html>

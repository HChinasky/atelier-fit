<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ \App\Services\Locale\Locales::direction($locale) }}"@hasSection('html-class') class="@yield('html-class')"@endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title')</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    {{-- Self-hosted fonts (scripts/fetch-vendor-assets.sh), versioned by mtime: Cloudflare keeps fonts.css for hours. --}}
    <link rel="stylesheet" href="{{ asset('fonts/fonts.css') }}?v={{ @filemtime(public_path('fonts/fonts.css')) }}">

    <style>
        /* The "Примірка" screens, step 1 and step 2 at 390px, in the site's
           palette (the table of the design hand-over, as the one-screen
           version on the phone's dashboard): the four site colours and two
           greys for the lines. */
        :root {
            --ink: #2D2D2D;
            --surface: #FFFFFF;
            --surface-2: #F0F0F0;
            --muted: #6B6B6B;
            --border: #E6E6E6;
            --dashed: #BDBDBD;
            --danger: #A6432E;

            --display: 'Playfair Display', Georgia, serif;
            --body: 'Manrope', system-ui, sans-serif;
            --pill: 999px;
        }

        *, *::before, *::after { box-sizing: border-box; }
        html { background: var(--surface); }
        body { margin: 0; background: var(--surface); color: var(--ink); font-family: var(--body); -webkit-font-smoothing: antialiased; }
        [x-cloak] { display: none !important; }
        a { color: var(--ink); }
        button { font-family: inherit; }

        /* The tab bar is fixed to the bottom of the viewport; the screen keeps room for it. */
        .screen { --tabs-h: 77px; width: 100%; max-width: 390px; min-height: 100vh; min-height: 100dvh; margin: 0 auto; display: flex; flex-direction: column;
            padding-bottom: calc(var(--tabs-h) + env(safe-area-inset-bottom)); background: var(--surface); position: relative; }
        .screen > * { flex-shrink: 0; }
        /* A screen that fits the viewport (html.ty-fit, as html.m-fit on the
           phone's dashboard): no page scroll, the header and the tabs keep
           their height, and the step's content is the column that gives. */
        html.ty-fit, html.ty-fit body { height: 100%; overflow: hidden; }
        html.ty-fit .screen { height: 100vh; height: 100dvh; min-height: 0; }
        html.ty-fit .screen > main { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }

        /* Header */
        .ty-head { display: flex; align-items: center; justify-content: space-between; padding: 22px 20px 0 20px; }
        .ty-logo { display: flex; flex-direction: column; gap: 2px; text-decoration: none; color: var(--ink); }
        .ty-logo-name { font-family: var(--display); font-weight: 600; font-size: 24px; line-height: 1; letter-spacing: -0.01em; }
        .ty-logo-r { font-size: 10px; vertical-align: top; position: relative; top: -2px; }
        .ty-logo-by { font-size: 10px; font-weight: 600; letter-spacing: 0.22em; color: var(--muted); text-transform: uppercase; }
        .ty-me { width: 44px; height: 44px; border-radius: 22px; border: 1px solid var(--border); background: var(--surface);
            display: flex; align-items: center; justify-content: center; padding: 0; }

        /* Step pill number: a dark circle with a white digit */
        .ty-num { width: 26px; height: 26px; border-radius: 13px; background: var(--ink); color: var(--surface); font-weight: 700; font-size: 13px;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

        /* Tab bar */
        .ty-tabs { position: fixed; left: 50%; bottom: 0; transform: translateX(-50%); width: 100%; max-width: 390px; z-index: 10;
            background: var(--surface); border-top: 1px solid var(--border); padding: 10px 8px calc(18px + env(safe-area-inset-bottom)) 8px;
            display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 0; }
        .ty-tab { display: flex; flex-direction: column; align-items: center; gap: 5px; min-height: 48px; justify-content: center; text-decoration: none;
            color: var(--muted); font-size: 11px; font-weight: 500; }
        .ty-tab[aria-current="page"] { color: var(--ink); font-weight: 700; }
        /* Right-to-left (Arabic). Layout mirrors through logical properties
           (inset-inline-*, margin-inline-*); what is left here cannot: the
           before/after slider and the photo carousel stay left-to-right like
           the images they move, arrows point the other way, and Arabic letters
           must join, so no letter-spacing. */
        [dir="rtl"] .compare, [dir="rtl"] .look-visual, [dir="rtl"] .pm-track { direction: ltr; }
        [dir="rtl"] :is(.back, .auth-back, .pm-back, .pricing-nav, .go) svg { transform: scaleX(-1); }
        [dir="rtl"] .plan-card .go:hover svg { transform: scaleX(-1) translateX(4px); }
        [dir="rtl"] .quiz { --slide: -28px; }
        :lang(ar) body, :lang(ar) body * { letter-spacing: 0 !important; }
    </style>
    @stack('styles')
</head>
<body>
@php($hanger = 'M12 4a2 2 0 1 1 2 2c-1 0-2 .8-2 2v1.2M12 9l9 6.2a1 1 0 0 1-.6 1.8H3.6a1 1 0 0 1-.6-1.8z')
@php($heart = 'M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z')
<div class="screen" id="tryon-screen">

    <header class="ty-head">
        <a class="ty-logo" href="{{ route('membership.account.dashboard') }}">
            <span class="ty-logo-name">Atelier Fit<span class="ty-logo-r">®</span></span>
            <span class="ty-logo-by">{{ __('tryon_steps.by') }}</span>
        </a>
        <a class="ty-me" href="{{ route('membership.account.settings') }}" aria-label="{{ __('tryon_steps.profile') }}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2D2D2D" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"></path></svg>
        </a>
    </header>

    @yield('content')

    <nav class="ty-tabs" aria-label="{{ __('tryon_steps.nav.label') }}">
        <a class="ty-tab" href="{{ route('membership.account.dashboard') }}">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"></path></svg>
            {{ __('tryon_steps.nav.home') }}
        </a>
        <a class="ty-tab" href="{{ route('membership.account.try-on.step1') }}" aria-current="page">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $hanger }}"></path></svg>
            {{ __('tryon_steps.nav.tryon') }}
        </a>
        <a class="ty-tab" href="{{ route('membership.account.wardrobe') }}">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"></rect><path d="M12 3v18M9.5 12v2M14.5 12v2"></path></svg>
            {{ __('tryon_steps.nav.wardrobe') }}
        </a>
        <a class="ty-tab" href="{{ route('membership.account.wardrobe') }}#favorites">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $heart }}"></path></svg>
            {{ __('tryon_steps.nav.favorites') }}
        </a>
        <a class="ty-tab" href="{{ route('membership.account.settings') }}">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"></path></svg>
            {{ __('tryon_steps.nav.profile') }}
        </a>
    </nav>
</div>

@stack('scripts')
<script defer src="{{ asset('vendor/alpine.min.js') }}"></script>
</body>
</html>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Colour labelling') · Atelier Fit</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    {{-- Versioned by mtime: Cloudflare and browsers keep fonts.css for hours, so a font swap otherwise stays invisible. --}}
    <link rel="stylesheet" href="{{ asset('fonts/fonts.css') }}?v={{ @filemtime(public_path('fonts/fonts.css')) }}">
    <style>
        :root {
            --ink: #2D2D2D;
            --base: #FFFFFF;
            --surface: #F0F0F0;
            --muted: #6B6B6B;
            --danger: #A6432E;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 28px 20px 64px;
            font: 15px/1.55 ui-sans-serif, system-ui, "Segoe UI", sans-serif;
            color: var(--ink); background: var(--base);
        }
        .wrap { max-width: 1040px; margin: 0 auto; }
        h1 { font-size: 22px; margin: 0 0 2px; letter-spacing: .01em; }
        h2 { font-size: 15px; margin: 0 0 12px; text-transform: uppercase;
             letter-spacing: .08em; color: var(--muted); font-weight: 600; }
        .lede { color: var(--muted); margin: 0 0 24px; }
        .card { background: var(--base); border: 1px solid rgba(45,45,45,.12);
                border-radius: 0; padding: 20px; margin-bottom: 20px; }
        .banner { background: var(--surface); border: 1px solid rgba(45,45,45,.14);
                  border-radius: 0; padding: 14px 16px; margin-bottom: 20px; }
        .banner b { display: block; margin-bottom: 2px; }
        .banner span { color: var(--muted); font-size: 14px; }
        label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 5px; }
        select, input[type=text], textarea { width: 100%; padding: 9px 10px; font: inherit;
            color: var(--ink); background: var(--base);
            border: 1px solid rgba(45,45,45,.2); border-radius: 0; }
        textarea { min-height: 80px; resize: vertical; }
        .row { display: flex; flex-wrap: wrap; gap: 16px; }
        .row > * { flex: 1 1 180px; }
        button, .button { font: inherit; font-weight: 600; padding: 10px 22px; border-radius: 0;
                 border: 1px solid var(--ink); background: var(--ink); color: var(--base);
                 cursor: pointer; text-decoration: none; display: inline-block; }
        button.ghost, .button.ghost { background: transparent; color: var(--ink); }
        table { border-collapse: collapse; width: 100%; font-variant-numeric: tabular-nums; }
        th, td { text-align: left; padding: 7px 10px; border-bottom: 1px solid rgba(45,45,45,.1); }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
        .pill { display: inline-block; padding: 2px 10px; border-radius: 0;
                font-size: 12px; font-weight: 600; background: var(--surface); }
        .pill.bad { background: var(--danger); color: var(--base); }
        .muted { color: var(--muted); }
        .small { font-size: 13px; }
        .grid { display: flex; flex-wrap: wrap; gap: 14px; }
        figure { margin: 0; }
        figure img { display: block; max-width: 100%; border-radius: 0;
                     border: 1px solid rgba(45,45,45,.12); background: var(--surface); }
        figcaption { color: var(--muted); font-size: 12px; margin-top: 5px; }
        a { color: var(--ink); }
        .counts { display: flex; gap: 26px; color: var(--muted); font-size: 14px; margin: 0 0 16px; }
        .counts b { color: var(--ink); font-size: 20px; display: block; font-weight: 600; }
        .error { color: var(--danger); font-size: 13px; margin: 4px 0 0; }
        .prose { max-width: 70ch; }
        .prose h1 { font-size: 22px; margin: 28px 0 8px; }
        .prose h2 { font-size: 17px; text-transform: none; letter-spacing: 0;
                    color: var(--ink); margin: 30px 0 8px; }
        .prose h3 { font-size: 15px; margin: 22px 0 4px; }
        .prose p, .prose li { color: var(--ink); }
        .prose code { background: var(--surface); padding: 1px 5px; border-radius: 0;
                      font-size: 13px; }
        .prose blockquote { margin: 0; padding-left: 14px;
                            border-left: 3px solid rgba(45,45,45,.2); color: var(--muted); }
    </style>
</head>
<body>
<div class="wrap">
    <h1>@yield('title', 'Colour labelling')</h1>
    <p class="lede">
        Internal. Atelier Fit &middot; not linked from the site.
        &middot; <a href="{{ route('stylist.color.guide') }}">How to label</a>
    </p>

    @yield('content')
</div>
</body>
</html>

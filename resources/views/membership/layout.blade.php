<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Services\Locale\Locales::direction() }}" class="@yield('html-class')">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('common.title'))</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    {{-- Design system extracted from the "Membership & Account" prototype
         (the design hand-over). Only its CSS tokens,
         layout and animations are taken — the proprietary runtime (support.js,
         React, <x-dc>/<sc-*> tags) is NOT used. Markup is plain Blade;
         interactivity is Alpine, same approach as the fitting-room page. --}}
    {{-- Self-hosted: see the note in ai-fitting-room.blade.php. Populate the files
         with scripts/fetch-vendor-assets.sh. --}}
    {{-- Versioned by mtime: Cloudflare and browsers keep fonts.css for hours, so a font swap otherwise stays invisible. --}}
    <link rel="stylesheet" href="{{ asset('fonts/fonts.css') }}?v={{ @filemtime(public_path('fonts/fonts.css')) }}">

    <style>
        /* ---- Design tokens: monochrome brand palette (same as the
               fitting-room page). Four colors carry the whole UI —
               #2D2D2D ink (text, dark panels, primary buttons),
               #FFFFFF base (page, cards, text on dark),
               #F0F0F0 second surface (sidebar, tiles, placeholders) — a
               neutral grey since the Desk_lk frame 1661:6416, which
               retired the warm cream (FBF8F3),
               #6B6B6B muted (labels, eyebrows, secondary text).
               Everything else is one of those at reduced alpha; --danger
               stays red because an error must not read as decoration. ---- */
        :root {
            --surface: #FFFFFF;
            --surface-2: #F0F0F0;
            --ink: #2D2D2D;
            --ink-2: #2D2D2D;
            --muted: #6B6B6B;
            --muted-2: #6B6B6B;
            /* Accent tokens kept under their old names (used across the
               screens); the accent itself is no longer gold. */
            --gold: #6B6B6B;
            --gold-dark: #6B6B6B;
            --danger: #A6432E;
            --placeholder: rgba(107, 107, 107, .6);
            --line: rgba(45, 45, 45, .12);
            --ease: cubic-bezier(.2, .7, .2, 1);
            color-scheme: light;
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { margin: 0; background: var(--surface); color: var(--ink-2);
            font-family: 'Montserrat', sans-serif; font-weight: 400; }
        ::placeholder { color: var(--placeholder); }
        [x-cloak] { display: none !important; }
        button { font-family: 'Montserrat', sans-serif; }

        /* ---- Animations (one-to-one from the prototype) ---- */
        @keyframes nspin { to { transform: rotate(360deg); } }
        @keyframes nshimmer { 0% { background-position: -160px 0; } 100% { background-position: 320px 0; } }
        @keyframes nsheet { from { transform: translateY(100%); } to { transform: translateY(0); } }
        @keyframes npop { from { opacity: 0; transform: translateY(14px) scale(.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
        @keyframes nfadein { from { opacity: 0; } to { opacity: 1; } }
        @keyframes nrise { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes ncaret { 0%, 100% { opacity: 0; } 50% { opacity: 1; } }
        @keyframes neq { 0%, 100% { transform: scaleY(.3); } 50% { transform: scaleY(1); } }

        /* ---- Shared typography ---- */
        .eyebrow { font-size: 9.5px; letter-spacing: .3em; color: var(--gold-dark);
            text-transform: uppercase; margin-bottom: 8px; }
        .page-title { font-family: 'Playfair Display', serif; font-weight: 500; font-size: 30px;
            line-height: 1.04; color: var(--ink-2); margin: 0; }
        @media (min-width: 834px) { .page-title { font-size: 34px; } }
        @media (min-width: 1280px) { .page-title { font-size: 40px; } }
        .lede { font-weight: 300; line-height: 1.6; color: var(--muted); font-size: 14px; }

        .btn-dark { padding: 16px; border: none; border-radius: 0; cursor: pointer;
            background: var(--ink-2); color: var(--surface); font-size: 12px; font-weight: 500;
            letter-spacing: .2em; text-transform: uppercase;
            transition: transform .3s var(--ease), box-shadow .3s ease, background .3s ease; }
        .btn-dark:hover { background: var(--ink); transform: translateY(-2px);
            box-shadow: 0 16px 32px -14px rgba(45, 45, 45, .5); }
        .btn-dark:active { transform: translateY(0) scale(.99); }
        .btn-gold-outline { padding: 14px 30px; border: 1px solid var(--gold); border-radius: 0;
            background: none; font-size: 11px; letter-spacing: .18em; color: var(--gold-dark);
            text-transform: uppercase; cursor: pointer; transition: background .3s ease, color .3s ease; }
        .btn-gold-outline:hover { background: var(--ink); color: var(--surface); }
        .btn-quiet { background: none; border: none; padding: 2px; font-size: 11px; letter-spacing: .14em;
            color: #6B6B6B; text-transform: uppercase; cursor: pointer; transition: color .3s ease; }
        .btn-quiet:hover { color: var(--ink-2); }

        /* ---- Account shell: sidebar >= 834px, bottom tabs below ---- */
        .acct-shell { display: flex; min-height: 100vh; background: var(--surface); }
        .acct-sidebar { display: none; }
        @media (min-width: 834px) {
            /* Pinned to the viewport: the content scrolls past it, and it
               scrolls inside itself only if it is taller than the screen. */
            .acct-sidebar { display: flex; flex-direction: column; width: 210px; flex: none;
                position: sticky; top: 0; height: 100vh; overflow-y: auto; align-self: flex-start;
                border-inline-end: 1px solid rgba(45, 45, 45, .08); padding: 26px 16px 22px; background: #F0F0F0; }
        }
        @media (min-width: 1280px) { .acct-sidebar { width: 248px; } }
        .acct-logo { display: block; height: 40px; width: auto; margin: 6px 8px 26px; }
        .acct-nav { display: flex; flex-direction: column; gap: 3px; flex: 1; }
        .acct-nav a { display: flex; align-items: center; gap: 12px; padding: 11px 13px;
            border-radius: 0; text-decoration: none; font-size: 13px; letter-spacing: .04em;
            color: #6B6B6B; transition: background .25s ease, color .25s ease; }
        .acct-nav a:hover { background: #F0F0F0; }
        .acct-nav a.active { background: var(--ink-2); color: var(--surface); }
        .acct-nav a.locked { color: #6B6B6B; }
        /* A locked section can still be the current one — keep it readable on ink. */
        .acct-nav a.active.locked { color: rgba(255, 255, 255, .65); }
        .acct-nav a.active:hover { background: var(--ink-2); }
        .acct-nav .nav-icon { display: flex; align-items: center; justify-content: center; width: 18px; }
        .acct-nav .nav-lock { margin-inline-start: auto; display: flex; }
        .acct-member { margin-top: 16px; }
        .acct-user { display: flex; align-items: center; gap: 11px; padding: 11px 12px;
            border-radius: 0; text-decoration: none; transition: background .25s ease; }
        .acct-user:hover { background: rgba(45, 45, 45, .04); }
        .acct-user .avatar { width: 60px; height: 60px; border-radius: 50%; flex: none;
            background: radial-gradient(circle at 30% 25%, #FFFFFF, rgba(107, 107, 107, .55) 70%, #2D2D2D); }
        .acct-user .u-name { display: block; font-size: 16px; font-weight: 300; color: var(--ink-2); }
        .acct-user .u-plan { display: block; font-size: 14px; font-weight: 300; letter-spacing: .04em; color: var(--ink-2); }
        .acct-chips { display: flex; gap: 10px; padding-inline-start: 12px; }
        .acct-chip { display: inline-flex; align-items: center; gap: 8px; padding: 4px 11px;
            border: 1px solid rgba(45, 45, 45, .2); border-radius: 0; font-size: 9.5px;
            letter-spacing: .08em; text-transform: uppercase; color: #6B6B6B; white-space: nowrap; }

        /* Phone top bar: the wordmark on one line, centred — the name in Prata,
           a hairline, the byline in spaced small caps on the name's baseline. */
        .acct-topbar { position: sticky; top: 0; z-index: 20; padding: 11px 20px 10px; background: rgba(255, 255, 255, .95);
            backdrop-filter: blur(10px); border-bottom: 1px solid rgba(45, 45, 45, .06); }
        .acct-wordmark { display: flex; align-items: baseline; justify-content: center; gap: 9px; white-space: nowrap;
            text-decoration: none; color: var(--ink-2); }
        .acct-wordmark .wm-name { font-family: 'Prata', serif; font-size: 17px; line-height: 1; letter-spacing: .01em; }
        .acct-wordmark .wm-sep { align-self: center; width: 1px; height: 11px; background: rgba(45, 45, 45, .3); }
        .acct-wordmark .wm-by { font-size: 8.5px; font-weight: 400; line-height: 1; letter-spacing: .24em; text-transform: uppercase; color: var(--muted); }
        .acct-wordmark:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 4px; }
        @media (min-width: 834px) { .acct-topbar { display: none; } }
        /* The bar takes a line of its own above the content. */
        .acct-shell { flex-wrap: wrap; align-content: flex-start; }
        .acct-topbar { flex-basis: 100%; }
        .acct-content { flex: 1; min-width: 0; padding: 11px 20px 120px; }
        @media (min-width: 834px) { .acct-content { padding: 40px 44px; } }

        .acct-tabs { position: fixed; inset-inline-start: 0; inset-inline-end: 0; bottom: 0; z-index: 20; display: flex;
            background: rgba(255, 255, 255, .95); backdrop-filter: blur(10px);
            border-top: 1px solid rgba(45, 45, 45, .08); padding: 6px 4px 8px; }
        @media (min-width: 834px) { .acct-tabs { display: none; } }
        .acct-tabs a { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 5px;
            padding: 6px 2px; text-decoration: none; color: #6B6B6B; }
        .acct-tabs a.active { color: var(--ink-2); }
        .acct-tabs a:focus-visible { outline: 2px solid var(--ink-2); outline-offset: -2px; }
        /* The drawn icons (Figma 1872:8303), painted in the link's colour
           through a mask so the active tab can darken them. */
        .acct-tabs .tab-icon { width: 25px; height: 25px; background: currentColor;
            -webkit-mask: var(--icon) center / contain no-repeat; mask: var(--icon) center / contain no-repeat; }
        .acct-tabs .tab-label { font-size: 10px; letter-spacing: .51px; }

        /* Dev-only plan switcher (Stage A mock: ?plan=..., no DB) */
        .plan-chips { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 24px; }
        .plan-chips .chips-label { font-size: 9px; letter-spacing: .2em; color: #6B6B6B; text-transform: uppercase; }
        .plan-chips a { font-size: 9.5px; letter-spacing: .08em; text-transform: uppercase; text-decoration: none;
            border: 1px solid rgba(45, 45, 45, .2); color: #6B6B6B; border-radius: 0; padding: 4px 11px; white-space: nowrap; }
        .plan-chips a.active { background: var(--ink-2); border-color: var(--ink-2); color: var(--surface); }

        .section-rise { animation: nrise .55s var(--ease) both; }

        /* ---- Page primitives shared by the Figma-built screens (dashboard,
               wardrobe): Prata headlines, square tracked buttons, before/after. ---- */
        /* --line-soft: panel and card borders, the frame's DDDDDD grey (ink at 16% on white). */
        .dl { --serif: 'Prata', 'Playfair Display', serif; --line-soft: rgba(45, 45, 45, .16); font-weight: 300; }
        .dl h1, .dl h2, .dl h3 { font-family: var(--serif); font-weight: 400; margin: 0; }
        .dl .lede { font-size: 16px; line-height: 1.5; color: var(--muted); margin: 8px 0 0; max-width: 42rem; }

        /* Section head: eyebrow + 38px title (+ optional actions on the right) */
        .dl-head { display: flex; flex-direction: column; gap: 22px; align-items: flex-start; }
        /* Heading and actions share a row only when both fit (the 1920px frame).
           Narrower, the actions move under the heading as a whole — squeezed
           beside it, they wrapped into a column, one button above the other. */
        @media (min-width: 1280px) { .dl-head { flex-direction: row; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; } }
        .dl-head h1 { font-size: 30px; line-height: 1.1; }
        @media (min-width: 834px) { .dl-head h1 { font-size: 38px; line-height: 41.6px; } }
        .dl-actions { display: flex; flex-wrap: wrap; gap: 14px; }
        @media (min-width: 1280px) { .dl-actions { gap: 31px; } }

        /* Buttons as drawn: square, light weight, tracked uppercase */
        .dl-btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            padding: 16px 23px; border: 1px solid var(--ink-2); border-radius: 0; cursor: pointer;
            font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 14px; letter-spacing: .13em;
            text-transform: uppercase; text-decoration: none; text-align: center; line-height: 1.4;
            transition: background .3s ease, color .3s ease, transform .3s var(--ease); }
        @media (min-width: 834px) { .dl-btn { font-size: 16px; } }
        .dl-btn:hover { transform: translateY(-2px); }
        .dl-btn:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 3px; }
        .dl-btn-dark { background: var(--ink-2); color: var(--surface); }
        .dl-btn-outline { background: none; color: var(--ink-2); }
        .dl-btn-outline:hover { background: var(--ink-2); color: var(--surface); }
        .dl-btn-white { background: var(--surface); color: var(--ink-2); border-color: var(--surface); }
        .dl-btn-block { width: 100%; }
        .dl-btn:disabled { cursor: default; transform: none; opacity: .45; }

        /* Before / after: the result is clipped from the left, a range input drives the split */
        .compare { position: absolute; inset: 0; }
        .compare img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; }
        .compare .after { clip-path: inset(0 0 0 var(--split)); }
        .compare .bar { position: absolute; top: 0; bottom: 0; left: var(--split); width: 3px; margin-left: -1.5px; background: var(--surface); }
        .compare .knob { position: absolute; top: 50%; left: var(--split); width: 40px; height: 40px; margin: -20px 0 0 -20px;
            border-radius: 50%; background: var(--surface); display: flex; align-items: center; justify-content: center; gap: 2px;
            box-shadow: 0 6px 18px -8px rgba(45, 45, 45, .6); }
        .compare input[type=range] { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0;
            opacity: 0; cursor: ew-resize; }

        /* Top line of the account content (frame 1456:10458): plan switcher on
           the left, credits + notifications + avatar on the right. */
        .acct-topline { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
        .acct-topline .plan-chips { margin-bottom: 0; }
        /* margin-left keeps it on the right when the dev switcher is absent. */
        .acct-topline-right { display: none; align-items: center; gap: 22px; margin-inline-start: auto; }
        @media (min-width: 834px) { .acct-topline-right { display: flex; } }
        /* The dev plan switcher stays off the phone's first screen (Figma
           1872:8187 has none); ?plan= in the address still switches. */
        @media (max-width: 833px) { .acct-topline { display: none; } }
        .acct-bell { width: 36px; height: 36px; border: none; background: none; padding: 0; color: var(--ink-2);
            display: flex; align-items: center; justify-content: center; cursor: default; }
        .acct-avatar { width: 60px; height: 60px; border-radius: 50%; flex: none; display: block;
            background: radial-gradient(circle at 30% 25%, #FFFFFF, rgba(107, 107, 107, .55) 70%, #2D2D2D); }
        /* The member's own picture, when set, covers the placeholder sphere. */
        .acct-user .avatar, .acct-avatar { overflow: hidden; }
        .acct-user .avatar img, .acct-avatar img { display: block; width: 100%; height: 100%; object-fit: cover; }

        /* ---- Cards / grids shared by account sections ---- */
        .look-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
        @media (min-width: 834px) { .look-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 1280px) { .look-grid { grid-template-columns: repeat(4, 1fr); } }
        .look-card { border: 1px solid rgba(45, 45, 45, .1); border-radius: 0; overflow: hidden;
            background: #FFFFFF; animation: nrise .5s var(--ease) both; }
        .look-media { position: relative; aspect-ratio: 3/4; }
        .look-media .media-label { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            font-size: 9px; letter-spacing: .2em; color: var(--muted); text-transform: uppercase; }
        .look-media .media-date { position: absolute; top: 10px; inset-inline-start: 10px; font-size: 8.5px; letter-spacing: .1em;
            color: rgba(255, 255, 255, .85); background: rgba(45, 45, 45, .4); backdrop-filter: blur(4px);
            padding: 3px 8px; border-radius: 0; }
        .look-body { padding: 12px 13px 14px; }
        .look-brand { font-size: 8.5px; letter-spacing: .2em; color: #6B6B6B; text-transform: uppercase; }
        .look-name { font-family: 'Playfair Display', serif; font-size: 14px; color: var(--ink-2); margin: 4px 0 12px; }
        .look-actions { display: flex; gap: 8px; }
        .look-actions .save { flex: 1; padding: 9px; border: 1px solid rgba(45, 45, 45, .18); border-radius: 0;
            background: none; font-size: 9.5px; letter-spacing: .08em; color: #6B6B6B; text-transform: uppercase;
            cursor: pointer; transition: border-color .3s ease, color .3s ease; }
        .look-actions .save:hover { border-color: var(--gold); color: var(--ink-2); }
        .look-actions .shop { flex: 1; padding: 9px; border: none; border-radius: 0; background: var(--ink-2);
            font-size: 9.5px; letter-spacing: .08em; color: var(--surface); text-transform: uppercase;
            cursor: pointer; transition: background .3s ease; }
        .look-actions .shop:hover { background: var(--ink); }

        /* Locked section (wardrobe / capsules on lower plans) */
        /* Preview and message share one grid cell, so the box is as tall as the
           taller of the two: an absolute overlay was squeezed into the preview's
           ~170px on a phone. */
        .locked-box { position: relative; display: grid; grid-template-columns: minmax(0, 1fr); border: 1px solid rgba(107, 107, 107, .35); border-radius: 0; overflow: hidden; }
        .locked-preview, .locked-overlay { grid-area: 1 / 1; }
        .locked-preview { filter: blur(3px); opacity: .5; pointer-events: none; padding: 22px;
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .locked-preview > div { aspect-ratio: 3/4; border-radius: 0;
            background: repeating-linear-gradient(45deg, #F0F0F0 0 9px, rgba(45, 45, 45, .05) 9px 18px); }
        .locked-preview.caps { grid-template-columns: 1fr 1fr; gap: 14px; }
        .locked-preview.caps > div { aspect-ratio: 16/10; border-radius: 0;
            background: repeating-linear-gradient(45deg, #F0F0F0 0 12px, rgba(45, 45, 45, .06) 12px 24px); }
        .locked-overlay { position: relative; display: flex; flex-direction: column; align-items: center;
            justify-content: center; text-align: center; padding: 28px;
            background: linear-gradient(rgba(255, 255, 255, .4), rgba(255, 255, 255, .85)); }
        .locked-overlay .lock-badge { width: 46px; height: 46px; border-radius: 50%; background: var(--surface);
            border: 1px solid rgba(107, 107, 107, .5); display: flex; align-items: center; justify-content: center;
            margin-bottom: 16px; }
        .locked-overlay h3 { font-family: 'Playfair Display', serif; font-weight: 500; font-size: 21px; color: var(--ink-2); margin: 0 0 8px; }
        .locked-overlay p { font-weight: 300; font-size: 13px; line-height: 1.6; color: #6B6B6B; margin: 0 0 18px; max-width: 32ch; }

        /* Empty state */
        .empty-box { border: 1px solid var(--line); border-radius: 0; padding: 64px 28px; text-align: center; }
        .empty-box .mark { width: 48px; height: 48px; border-radius: 50%; border: 1px solid rgba(107, 107, 107, .45);
            display: flex; align-items: center; justify-content: center; margin: 0 auto 18px;
            font-family: 'Playfair Display', serif; font-size: 22px; color: var(--gold); }
        .empty-box h3 { font-family: 'Playfair Display', serif; font-weight: 500; font-size: 21px; color: var(--ink-2); margin: 0; }
        .empty-box p { font-weight: 300; font-size: 13px; line-height: 1.6; color: #6B6B6B; margin: 10px auto 20px; max-width: 30ch; }

        /* ---- Modals: bottom sheet on mobile, centered card on >= 768px ---- */
        .modal-backdrop { position: fixed; inset: 0; z-index: 50; display: flex; flex-direction: column; justify-content: flex-end; }
        @media (min-width: 768px) { .modal-backdrop { align-items: center; justify-content: center; padding: 40px; } }
        .modal-scrim { position: absolute; inset: 0; background: rgba(45, 45, 45, .5);
            backdrop-filter: blur(4px); animation: nfadein .3s ease; }
        .modal-card { position: relative; background: var(--surface); width: 100%;
            border-radius: 0; padding: 14px 24px 30px;
            animation: nsheet .42s cubic-bezier(.22, 1, .36, 1); box-shadow: 0 -10px 40px rgba(45, 45, 45, .2); }
        @media (min-width: 768px) {
            .modal-card { width: 440px; max-width: 100%; border-radius: 0; padding: 34px 38px;
                animation: npop .4s cubic-bezier(.22, 1, .36, 1); box-shadow: 0 30px 70px rgba(45, 45, 45, .35); }
        }
        /* The paywall: the plans themselves, not a link to them. */
        .modal-card.plans-card { max-height: 92vh; overflow-y: auto; }
        @media (min-width: 768px) { .modal-card.plans-card { width: min(1000px, 100%); padding: 38px 44px; } }
        .plan-mini-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; margin: 0 0 16px; text-align: start; }
        @media (min-width: 768px) { .plan-mini-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; } }
        .plan-mini { position: relative; display: flex; flex-direction: column; gap: 6px; padding: 16px 16px 14px;
            background: var(--surface); border: 1px solid rgba(45, 45, 45, .14); }
        .plan-mini.popular { border-color: var(--ink-2); }
        .plan-mini.vip { background: var(--ink-2); color: var(--surface); border-color: var(--ink-2); }
        .plan-mini .badge { position: absolute; top: -9px; inset-inline-start: 14px; padding: 3px 10px; background: var(--ink-2);
            color: var(--surface); font-size: 8.5px; letter-spacing: .16em; text-transform: uppercase; }
        .plan-mini .name { font-size: 10px; letter-spacing: .24em; text-transform: uppercase; color: var(--muted); }
        .plan-mini.vip .name { color: rgba(255, 255, 255, .7); }
        .plan-mini .price { font-family: 'Playfair Display', serif; font-size: 28px; line-height: 1; color: inherit; }
        .plan-mini .price small { font-family: 'Montserrat', sans-serif; font-size: 11px; color: var(--muted); }
        .plan-mini.vip .price small { color: rgba(255, 255, 255, .7); }
        .plan-mini .tokens { font-size: 12px; line-height: 1.45; color: var(--muted); }
        .plan-mini.vip .tokens { color: rgba(255, 255, 255, .8); }
        .plan-mini-btn { margin-top: 8px; padding: 11px; border: 1px solid rgba(45, 45, 45, .22); background: transparent; color: var(--muted);
            font-family: 'Montserrat', sans-serif; font-size: 10px; letter-spacing: .16em; text-transform: uppercase; cursor: default; opacity: .75; }
        .plan-mini.vip .plan-mini-btn { border-color: rgba(255, 255, 255, .4); color: rgba(255, 255, 255, .85); }
        .plan-soon { margin: 0 0 16px; padding: 11px 14px; background: var(--surface-2); font-weight: 300; font-size: 12px;
            line-height: 1.55; color: var(--ink-2); text-align: start; }
        .plan-soon strong { font-weight: 500; }
        .modal-handle { width: 38px; height: 4px; border-radius: 0; background: rgba(45, 45, 45, .16); margin: 0 auto 20px; }
        @media (min-width: 768px) { .modal-handle { display: none; } }
        .modal-close { position: absolute; top: 18px; inset-inline-end: 20px; width: 28px; height: 28px; border-radius: 50%;
            border: 1px solid rgba(45, 45, 45, .14); background: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center; }
        .modal-card h2 { font-family: 'Playfair Display', serif; font-weight: 500; font-size: 30px; line-height: 1.08;
            color: var(--ink-2); margin: 0 0 12px; }
        .modal-privacy { display: flex; align-items: center; gap: 8px; justify-content: center;
            margin-top: 22px; padding-top: 18px; border-top: 1px solid rgba(45, 45, 45, .08); }
        .modal-privacy span { font-weight: 300; font-size: 10.5px; letter-spacing: .03em; color: var(--muted-2); }

        /* ---- Auth overlays (email / otp): full-screen, card on >= 768px ---- */
        .auth-screen { position: fixed; inset: 0; z-index: 50; overflow-y: auto;
            background: radial-gradient(120% 80% at 50% 0%, #FFFFFF, #F0F0F0);
            display: flex; align-items: flex-start; justify-content: center; padding: 40px 26px; }
        @media (min-width: 768px) { .auth-screen { align-items: center; padding: 40px; } }
        .auth-card { width: 100%; max-width: 420px; }
        @media (min-width: 768px) {
            .auth-card { width: 440px; max-width: 100%; background: var(--surface);
                border: 1px solid rgba(45, 45, 45, .08); border-radius: 0; padding: 40px;
                box-shadow: 0 30px 70px -34px rgba(45, 45, 45, .4); }
        }
        .auth-back { display: flex; align-items: center; gap: 7px; background: none; border: none; padding: 0;
            margin-bottom: 28px; font-size: 11px; letter-spacing: .16em; color: #6B6B6B;
            text-transform: uppercase; cursor: pointer; transition: color .3s ease; }
        .auth-back:hover { color: var(--ink-2); }
        /* Close (X) for the full-screen auth steps — same circle as .modal-close,
           pinned to the screen corner (the steps have no scrim to click). */
        .auth-close { position: absolute; top: 22px; inset-inline-end: 22px; width: 32px; height: 32px; border-radius: 50%;
            border: 1px solid rgba(45, 45, 45, .14); background: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center; transition: border-color .3s ease; }
        .auth-close:hover { border-color: rgba(45, 45, 45, .4); }

        /* E-mail field */
        .email-field { display: flex; align-items: center; border: 1px solid rgba(45, 45, 45, .18);
            border-radius: 0; background: #FFFFFF;
            transition: border-color .3s ease, box-shadow .3s ease; }
        .email-field:focus-within { border-color: var(--ink-2); box-shadow: 0 0 0 4px rgba(45, 45, 45, .1); }
        .email-field.error { border-color: var(--danger); box-shadow: 0 0 0 4px rgba(166, 67, 46, .1); }
        .email-input { flex: 1; min-width: 0; border: none; outline: none; background: none;
            padding: 15px 16px; font-family: 'Montserrat', sans-serif; font-size: 16px;
            letter-spacing: .02em; color: var(--ink-2); }
        .email-input::placeholder { color: var(--muted-2); }
        /* "or" between the code and the providers, then one outlined button per provider. */
        .auth-divider { display: flex; align-items: center; gap: 12px; margin: 22px 0 14px; }
        .auth-divider span:not(.label) { flex: 1; height: 1px; background: rgba(45, 45, 45, .1); }
        .auth-divider .label { font-size: 10.5px; letter-spacing: .18em; text-transform: uppercase; color: var(--muted-2); }
        .auth-providers { display: flex; flex-direction: column; gap: 10px; }
        .btn-provider { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%;
            padding: 14px 16px; border: 1px solid rgba(45, 45, 45, .18); border-radius: 0; background: #FFFFFF;
            font-family: 'Montserrat', sans-serif; font-size: 12px; letter-spacing: .12em; text-transform: uppercase;
            color: var(--ink-2); text-decoration: none; transition: border-color .3s ease, background .3s ease; }
        .btn-provider:hover { border-color: var(--ink-2); background: var(--surface-2); }
        .btn-provider svg { flex: none; }
        .field-error { display: flex; align-items: center; gap: 7px; margin-top: 11px;
            font-size: 12.5px; color: var(--danger); }
        .btn-cta { width: 100%; margin-top: 26px; }
        .btn-cta:disabled { background: transparent; color: #6B6B6B; border: 1px solid rgba(45, 45, 45, .16);
            cursor: default; transform: none; box-shadow: none; }
        .turnstile-stub { display: flex; justify-content: center; margin-top: 22px; }
        .turnstile-stub > div { display: flex; align-items: center; gap: 9px; padding: 9px 13px;
            border: 1px solid rgba(45, 45, 45, .12); border-radius: 0; background: var(--surface-2); }
        .turnstile-stub .spinner { width: 15px; height: 15px; border-radius: 50%;
            border: 1.6px solid rgba(45, 45, 45, .18); border-top-color: var(--ink-2);
            animation: nspin 1.1s linear infinite; }
        .turnstile-stub span { font-size: 10.5px; letter-spacing: .06em; color: var(--muted-2); }

        /* OTP cells (one hidden input drives six display cells) */
        .otp-row { position: relative; display: flex; gap: 9px; justify-content: space-between; }
        .otp-cell { flex: 1; aspect-ratio: 1; max-width: 56px; display: flex; align-items: center;
            justify-content: center; font-family: 'Playfair Display', serif; font-size: 26px; color: var(--ink-2);
            border-radius: 0; background: #FFFFFF; border: 1px solid rgba(45, 45, 45, .18); }
        .otp-cell.focus { border-color: var(--ink-2); box-shadow: 0 0 0 4px rgba(45, 45, 45, .1); }
        .otp-cell.error { border-color: rgba(166, 67, 46, .5); box-shadow: none; }
        .otp-cell .caret { width: 2px; height: 24px; background: var(--ink-2); animation: ncaret 1.1s step-end infinite; }
        .otp-hidden { position: absolute; inset: 0; width: 100%; opacity: 0; border: none;
            font-size: 16px; letter-spacing: 2em; cursor: pointer; }
        .otp-locked { border: 1px solid rgba(166, 67, 46, .3); background: rgba(166, 67, 46, .05);
            border-radius: 0; padding: 26px 22px; text-align: center; }
        .otp-locked .mark { width: 44px; height: 44px; border-radius: 50%; border: 1px solid rgba(166, 67, 46, .4);
            display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; }
        .otp-locked h3 { font-family: 'Playfair Display', serif; font-weight: 500; font-size: 19px; color: var(--ink-2); margin: 0 0 7px; }
        .otp-locked p { font-weight: 300; font-size: 13px; line-height: 1.55; color: #6B6B6B; margin: 0; }
        .resend-link { background: none; border: none; font-size: 12px; letter-spacing: .16em; color: var(--gold-dark);
            text-transform: uppercase; border-bottom: 1px solid rgba(107, 107, 107, .5); padding: 0 0 2px; cursor: pointer; }

        /* Payment placeholder (Stripe Elements / Checkout.com mounts here later —
           we never render our own card fields) */
        .btn-applepay { width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 15px; border: none; border-radius: 0; background: #000; color: #fff;
            font-size: 14px; letter-spacing: .02em; cursor: pointer; transition: opacity .3s ease; }
        .btn-applepay:hover { opacity: .85; }
        .pay-divider { display: flex; align-items: center; gap: 12px; margin: 18px 0; }
        .pay-divider span:first-child, .pay-divider span:last-child { flex: 1; height: 1px; background: rgba(45, 45, 45, .1); }
        .pay-divider .label { flex: none; font-size: 10px; letter-spacing: .14em; color: #6B6B6B; text-transform: uppercase;
            height: auto; background: none; }
        .pay-frame { border: 1px dashed rgba(45, 45, 45, .22); border-radius: 0; padding: 22px 18px; background: var(--surface-2); }
        .pay-frame .frame-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .pay-frame .frame-head span:first-child { font-size: 10px; letter-spacing: .14em; color: #6B6B6B; text-transform: uppercase; }
        .pay-frame .frame-head span:last-child { font-size: 9.5px; letter-spacing: .1em; color: var(--gold); text-transform: uppercase; }
        .pay-shimmer { height: 44px; border-radius: 0;
            background: linear-gradient(100deg, #F0F0F0 30%, #FFFFFF 50%, #F0F0F0 70%);
            background-size: 300px 100%; animation: nshimmer 1.6s linear infinite; }


        /* ---- Confirm-delete dialog ---- */
        .confirm-backdrop { position: fixed; inset: 0; z-index: 60; display: flex; align-items: center;
            justify-content: center; padding: 28px; }
        .confirm-card { position: relative; width: 400px; max-width: 100%; background: var(--surface);
            border-radius: 0; padding: 30px; animation: npop .38s cubic-bezier(.22, 1, .36, 1);
            box-shadow: 0 30px 70px rgba(45, 45, 45, .4); }
        .confirm-card .mark { width: 48px; height: 48px; border-radius: 50%; border: 1px solid rgba(166, 67, 46, .4);
            display: flex; align-items: center; justify-content: center; margin-bottom: 18px; }
        .confirm-card h2 { font-size: 23px; }
        .btn-danger { width: 100%; padding: 15px; border: none; border-radius: 0; background: var(--danger);
            color: var(--surface); font-size: 11.5px; font-weight: 500; letter-spacing: .16em;
            text-transform: uppercase; cursor: pointer; transition: background .3s ease; }
        .btn-danger:hover { background: #8F3826; }
        .btn-keep { width: 100%; margin-top: 10px; padding: 14px; border: 1px solid rgba(45, 45, 45, .18);
            border-radius: 0; background: none; font-size: 11px; letter-spacing: .14em; color: #6B6B6B;
            text-transform: uppercase; cursor: pointer; }

        @yield('styles')

        /* Viewport switches: the desktop frames vs the LK_mob ones. Both
           variants sit in the markup; CSS shows the one for the viewport.
           Each side hides the other's elements with !important inside its own
           media query, so no page rule can bring them back; in the viewport
           they belong to, the elements simply keep their own display. */
        @media (min-width: 834px) { .only-mobile { display: none !important; } }
        @media (max-width: 833px) { .only-desktop { display: none !important; } }
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
</head>
<body>
    {{-- Escape closes whatever overlay is open — same action as the X / scrim. --}}
    <div x-data="membership()" x-init="init()" x-on:keydown.escape.window="closeModal()">
        @yield('content')

        {{-- No legal footer: removed on the owner's call (2026-10-14). The sign-in
             page still links the Terms and the Privacy Policy. --}}

        {{-- The login page IS the auth screen: it renders the email/otp steps
             inline, so the overlay copies of them must stay out of the DOM. --}}
        @unless ($withoutOverlays ?? false)
            @include('membership.partials.modals')
        @endunless

    </div>

    {{-- No bundler: Alpine from a CDN, same as the fitting-room page. --}}
    <script defer src="{{ asset('vendor/alpine.min.js') }}"></script>
    @if (config('services.turnstile.site_key'))
        {{-- Cloudflare Turnstile: rendered in the e-mail step, verified
             server-side before every code is sent. --}}
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif

    <script>
        function membership() {
            return {
                // Which overlay is open: gate | email | otp | paywall | payment | null.
                modal: null,

                // E-mail entry. A loose shape check here; the back end
                // validates the address properly.
                email: '',
                emailError: false,
                emailErrorText: @jsText(__('login.email.invalid')),
                sending: false,

                // OTP entry (real endpoints; states mirror the server statuses).
                otpCode: '',
                otpError: false,
                otpErrorText: @jsText(__('login.otp.invalid')),
                otpBlocked: false,
                verifying: false,
                resendLeft: 59,
                _resendT: null,

                confirmDelete: false,

                openModal(name) {
                    this.modal = name;
                },
                closeModal() {
                    this.modal = null;
                },

                // ---- plumbing: CSRF + JSON POST + a stable per-browser id ----
                post(url, payload) {
                    return fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify(payload),
                    }).then(res => res.json().catch(() => ({})).then(data => ({ http: res.status, data })));
                },
                fingerprint() {
                    let fp = localStorage.getItem('noor_fp');
                    if (! fp) {
                        fp = crypto.randomUUID ? crypto.randomUUID()
                            : Math.random().toString(36).slice(2) + Date.now().toString(36);
                        localStorage.setItem('noor_fp', fp);
                    }
                    return fp;
                },
                turnstileToken() {
                    return document.querySelector('[name="cf-turnstile-response"]')?.value || null;
                },

                // ---- e-mail: the address as typed, judged server-side ----
                emailValue() {
                    return this.email.trim().toLowerCase();
                },
                emailValid() {
                    // Loose gate only — the real check is the back end's.
                    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.emailValue());
                },
                emailDisplay() {
                    return this.emailValue();
                },

                async sendCode() {
                    if (! this.emailValid()) {
                        this.emailError = true;
                        this.emailErrorText = @jsText(__('login.email.invalid'));
                        return;
                    }
                    if (this.sending) return;
                    this.sending = true;
                    try {
                        const { http, data } = await this.post('{{ route('auth.email.send') }}', {
                            email: this.emailValue(),
                            fingerprint: this.fingerprint(),
                            turnstile_token: this.turnstileToken(),
                        });
                        if (data.status === 'sent') {
                            this.resetOtp(data.resend_in ?? 60);
                            this.openModal('otp');
                        } else if (data.status === 'waitlisted') {
                            // Pre-launch: the address is kept, the page says so.
                            window.location.href = data.redirect;
                            return;
                        } else if (data.status === 'blocked') {
                            this.resetOtp(0);
                            this.otpBlocked = true;
                            this.openModal('otp');
                        } else {
                            this.emailError = true;
                            this.emailErrorText = data.message
                                ?? (http === 422 ? @jsText(__('login.email.invalid')) : @jsText(__('common.something_wrong')));
                        }
                    } catch (e) {
                        this.emailError = true;
                        this.emailErrorText = @jsText(__('common.something_wrong'));
                    } finally {
                        this.sending = false;
                    }
                },

                // ---- otp: one hidden input drives six display cells ----
                resetOtp(resendIn = 59) {
                    this.otpCode = '';
                    this.otpError = false;
                    this.otpBlocked = false;
                    this.startResendTimer(resendIn);
                },
                onOtpInput(event) {
                    this.otpCode = event.target.value.replace(/\D/g, '').slice(0, 6);
                    event.target.value = this.otpCode;
                    this.otpError = false;
                },
                async verifyCode() {
                    if (this.otpBlocked || this.verifying || this.otpCode.length !== 6) return;
                    this.verifying = true;
                    try {
                        const { data } = await this.post('{{ route('auth.email.verify') }}', {
                            email: this.emailValue(),
                            code: this.otpCode,
                        });
                        if (data.status === 'ok') {
                            window.location.href = data.redirect;
                        } else if (data.status === 'blocked') {
                            this.otpBlocked = true;
                        } else if (data.status === 'expired') {
                            this.otpError = true;
                            this.otpErrorText = @jsText(__('login.otp.expired'));
                        } else {
                            this.otpError = true;
                            this.otpErrorText = data.attempts_left === 1
                                ? @jsText(__('login.otp.invalid_last')) : @jsText(__('login.otp.invalid'));
                        }
                    } catch (e) {
                        this.otpError = true;
                        this.otpErrorText = @jsText(__('common.something_wrong'));
                    } finally {
                        this.verifying = false;
                    }
                },
                startResendTimer(seconds = 59) {
                    clearInterval(this._resendT);
                    this.resendLeft = Math.max(0, Math.round(seconds));
                    this._resendT = setInterval(() => {
                        if (this.resendLeft > 0) { this.resendLeft--; } else { clearInterval(this._resendT); }
                    }, 1000);
                },
                resendLabel() {
                    const m = Math.floor(this.resendLeft / 60), s = this.resendLeft % 60;
                    return m + ':' + String(s).padStart(2, '0');
                },
                async resend() {
                    if (this.resendLeft > 0) return;
                    const { data } = await this.post('{{ route('auth.email.send') }}', {
                        email: this.emailValue(),
                        fingerprint: this.fingerprint(),
                        turnstile_token: this.turnstileToken(),
                    });
                    if (data.status === 'sent') {
                        this.resetOtp(data.resend_in ?? 60);
                    } else if (data.status === 'blocked') {
                        this.otpBlocked = true;
                    } else if (data.status === 'rate_limited') {
                        this.startResendTimer(Math.min(data.retry_after ?? 60, 3600));
                    } else {
                        this.otpError = true;
                        this.otpErrorText = data.message ?? @jsText(__('login.otp.resend_failed'));
                    }
                },

            };
        }
    </script>
    @yield('scripts')
</body>
</html>

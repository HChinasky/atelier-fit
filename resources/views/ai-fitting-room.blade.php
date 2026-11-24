<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Atelier Fit — Віртуальна примірочна</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    {{-- Design system extracted from the brand prototype (Claude Design .dc.html).
         We take ONLY its CSS / tokens / layout / animations — the proprietary
         runtime (support.js, React, <x-dc>/<sc-*> tags) is NOT used. Markup is
         plain Blade; interactivity stays Alpine + Dropzone, exactly as before. --}}
    {{-- Fonts and libraries are served from OUR domain, not from a public CDN:
         fetching them elsewhere handed the visitor's IP to that company before the
         page had rendered, which is a disclosure we do not need to make. Populate
         public/fonts and public/vendor with scripts/fetch-vendor-assets.sh. --}}
    {{-- Versioned by mtime: Cloudflare and browsers keep fonts.css for hours, so a font swap otherwise stays invisible. --}}
    <link rel="stylesheet" href="{{ asset('fonts/fonts.css') }}?v={{ @filemtime(public_path('fonts/fonts.css')) }}">
    <link rel="stylesheet" href="{{ asset('vendor/dropzone.css') }}">

    <style>
        /* ---- Design tokens: monochrome brand palette (same four colors as
               the membership layout) ---- */
        :root {
            --surface: #FFFFFF;        /* page, cards, text on dark */
            --surface-2: #F0F0F0;      /* second surface: panels, placeholders */
            --ink: #2D2D2D;            /* darkest */
            --ink-2: #2D2D2D;          /* headings */
            --muted: #6B6B6B;          /* body text */
            --muted-2: #6B6B6B;        /* secondary */
            --gold: #6B6B6B;           /* accent (no longer gold) */
            --gold-dark: #6B6B6B;      /* accent text / eyebrows */
            --danger: #A6432E;         /* errors stay red on purpose */
            --placeholder: rgba(107, 107, 107, .6);
            --line: rgba(45, 45, 45, .12);
            --ease: cubic-bezier(.2, .7, .2, 1);
            color-scheme: light;
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { margin: 0; background: var(--surface-2); color: var(--ink-2);
            font-family: 'Montserrat', sans-serif; font-weight: 400; }
        ::placeholder { color: var(--placeholder); }
        [x-cloak] { display: none !important; }

        /* ---- Animations (one-to-one from the prototype) ---- */
        @keyframes nspin { to { transform: rotate(360deg); } }
        @keyframes nshimmer { 0% { background-position: -160px 0; } 100% { background-position: 320px 0; } }
        @keyframes nsheet { from { transform: translateY(100%); } to { transform: translateY(0); } }
        @keyframes npop { from { opacity: 0; transform: translateY(14px) scale(.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
        @keyframes nfade { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes nfadein { from { opacity: 0; } to { opacity: 1; } }
        @keyframes npulse { 0%, 100% { opacity: .35; } 50% { opacity: 1; } }
        @keyframes nprog { from { width: 6%; } to { width: 96%; } }
        @keyframes nrise { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes nreveal { from { opacity: 0; transform: scale(1.05); } to { opacity: 1; transform: scale(1); } }
        @keyframes nsweep { 0% { transform: translateX(-135%); } 100% { transform: translateX(135%); } }
        @keyframes nfadeup { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* ---- App surface ---- */
        .app { max-width: 1180px; margin: 0 auto; background: var(--surface);
            min-height: 100vh; overflow: hidden; }
        @media (min-width: 768px) {
            body { padding: 22px 20px 48px; }
            .app { min-height: 0; border-radius: 0; border: 1px solid rgba(45, 45, 45, .08);
                box-shadow: 0 40px 90px -34px rgba(45, 45, 45, .5); }
        }

        /* ---- Brand header ---- */
        .brand-header { text-align: center; padding: 18px 24px; animation: nrise .7s var(--ease) both; }
        @media (min-width: 768px) { .brand-header { padding: 26px 24px 18px; } }
        .brand-logo { display: block; margin: 0 auto 15px; height: clamp(48px, 8vw, 64px); width: auto; }
        .brand-tag { font-size: 9.5px; letter-spacing: .34em; color: var(--gold);
            text-transform: uppercase; margin-top: 4px; }

        .eyebrow { font-size: 10px; letter-spacing: .3em; color: var(--gold-dark);
            text-transform: uppercase; margin-bottom: 10px; }
        h1.section-title { font-family: 'Playfair Display', serif; font-weight: 500;
            font-size: clamp(33px, 5vw, 52px); line-height: 1.04; color: var(--ink-2);
            margin: 0; letter-spacing: .005em; }
        h2.section-title { font-family: 'Playfair Display', serif; font-weight: 500;
            font-size: clamp(24px, 3.4vw, 32px); line-height: 1.06; color: var(--ink-2); margin: 0; }
        .lede { font-weight: 300; line-height: 1.6; color: var(--muted);
            margin: 12px 0 0; max-width: 34ch; font-size: 14px; }

        /* ---- Main layout (mobile-first; split at tablet+) ---- */
        .main-wrap { padding: 6px 22px 4px; }
        .left-col { margin-bottom: 6px; }
        .right-col { margin-top: 34px; }
        @media (min-width: 768px) {
            .main-wrap { display: flex; gap: 36px; align-items: flex-start; padding: 6px 40px 4px; }
            .left-col { width: 42%; flex: none; margin-bottom: 0; }
            .right-col { flex: 1; min-width: 0; margin-top: 0; }
        }
        @media (min-width: 1080px) {
            .main-wrap { gap: 60px; padding: 6px 72px 4px; }
            .left-col { width: 33%; }
        }

        /* ---- Upload zone (Dropzone is mounted on #photo-dropzone) ---- */
        .step-block { margin: 0 0 18px; animation: nrise .6s var(--ease) both; }
        #photo-dropzone.dropzone { position: relative; cursor: pointer; border-radius: 0;
            border: 1px solid rgba(45, 45, 45, .16); background: repeating-linear-gradient(135deg, #F0F0F0 0 12px, rgba(45, 45, 45, .04) 12px 24px);
            min-height: 470px; display: flex; flex-direction: column; align-items: center; justify-content: center;
            text-align: center; padding: 24px; transition: border-color .3s ease, background .3s ease, box-shadow .3s ease;
            animation: nrise .6s var(--ease) both; animation-delay: .08s; }
        @media (min-width: 768px) { #photo-dropzone.dropzone { min-height: 500px; } }
        @media (min-width: 1080px) { #photo-dropzone.dropzone { min-height: 560px; } }
        #photo-dropzone.dropzone:hover { border-color: rgba(107, 107, 107, .6); }
        #photo-dropzone .dz-message { margin: 0; }
        #photo-dropzone .camera-icon { display: flex; width: 56px; height: 56px; margin: 0 auto 16px;
            border-radius: 50%; border: 1px solid rgba(107, 107, 107, .55); align-items: center; justify-content: center;
            font-size: 24px; }
        #photo-dropzone .dz-title { font-family: 'Playfair Display', serif; font-size: 18px; color: var(--ink-2); letter-spacing: .01em; }
        #photo-dropzone .dz-sub { font-weight: 300; font-size: 11.5px; letter-spacing: .04em; color: var(--muted-2); margin-top: 8px; }
        #photo-dropzone .dz-preview { border-radius: 0; overflow: hidden; }
        /* The uploaded-photo preview filling the patterned upload zone. Bound to
           the client-side data URL (beforePreview); object-fit keeps the whole
           frame inside the rounded zone. */
        #photo-dropzone .photo-preview { position: absolute; inset: 0; z-index: 4; border-radius: 0;
            overflow: hidden; background: repeating-linear-gradient(135deg, #F0F0F0 0 12px, rgba(45, 45, 45, .04) 12px 24px); }
        #photo-dropzone .photo-preview img { width: 100%; height: 100%; object-fit: contain; display: block; }
        /* Dropzone's own default thumbnail/details are hidden — we render our own
           full-zone preview above. Keep only its remove link, restyled as a pill. */
        #photo-dropzone .dz-preview .dz-image,
        #photo-dropzone .dz-preview .dz-details,
        #photo-dropzone .dz-preview .dz-progress,
        #photo-dropzone .dz-preview .dz-error-message,
        #photo-dropzone .dz-preview .dz-success-mark,
        #photo-dropzone .dz-preview .dz-error-mark { display: none; }
        #photo-dropzone .dz-remove { position: absolute; bottom: 54px; left: 50%; transform: translateX(-50%);
            z-index: 20; background: rgba(45, 45, 45, .72); color: #FFFFFF; padding: 6px 16px; border-radius: 0;
            font-size: 11px; letter-spacing: .05em; text-decoration: none; border: 0; }
        #photo-dropzone .dz-remove:hover { background: rgba(45, 45, 45, .92); }
        /* Our own "replace photo" pill over the preview — does not depend on
           Dropzone's (unreliable in this v6 beta) remove link. */
        #photo-dropzone .replace-photo { position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%);
            z-index: 6; background: rgba(45, 45, 45, .72); color: #FFFFFF; padding: 7px 18px; border-radius: 0;
            font-size: 11px; letter-spacing: .05em; border: 0; cursor: pointer; font-family: inherit; }
        #photo-dropzone .replace-photo:hover { background: rgba(45, 45, 45, .92); }
        .photo-error { color: var(--danger); font-size: 13px; margin: 10px 0 0; }

        /* ---- Privacy panel ---- */
        /* ---- Consent gate (sits above the upload zone) ---- */
        .consent { margin-bottom: 14px; background: var(--surface-2); border: 1px solid rgba(107, 107, 107, .3);
            border-radius: 0; padding: 14px 16px; }
        .consent-row { display: flex; gap: 11px; align-items: flex-start; cursor: pointer; }
        .consent-row input { flex: none; margin-top: 2px; width: 16px; height: 16px; accent-color: #2D2D2D; cursor: pointer; }
        .consent-row span { font-weight: 300; font-size: 12.5px; line-height: 1.6; color: #2D2D2D; }
        .consent-links { margin: 9px 0 0 27px; font-size: 11.5px; }
        .consent-links a { color: #6B6B6B; text-decoration: underline; margin-right: 14px; }
        .consent-terms { margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(107, 107, 107, .22); }
        .consent-terms a { color: #6B6B6B; text-decoration: underline; }
        /* Until consent is given the upload zone is visibly inert. The real gate is
           the server; this only keeps the interface honest about it. */
        #photo-dropzone.consent-blocked { opacity: .45; pointer-events: none; }

        .privacy { margin-top: 20px; background: var(--surface-2); border: 1px solid rgba(107, 107, 107, .3);
            border-radius: 0; padding: 18px 20px; display: flex; gap: 15px; align-items: flex-start;
            animation: nrise .6s var(--ease) both; animation-delay: .18s; }
        .privacy .lock { flex: none; width: 36px; height: 36px; border-radius: 50%; background: #FFFFFF;
            border: 1px solid rgba(107, 107, 107, .4); display: flex; align-items: center; justify-content: center; font-size: 17px; }
        .privacy .eyebrow { margin-bottom: 7px; }
        .privacy p { font-weight: 300; font-size: 13px; line-height: 1.62; color: #6B6B6B; margin: 0; }

        /* ---- Catalog ---- */
        .catalog-head { display: flex; align-items: baseline; justify-content: space-between;
            margin-bottom: 18px; animation: nrise .6s var(--ease) both; animation-delay: .1s; }
        .catalog-count { font-size: 11px; letter-spacing: .04em; color: var(--muted-2); white-space: nowrap; }
        .catalog-hint { margin-top: 6px; font-size: 11.5px; line-height: 1.4; color: var(--muted-2); max-width: 38ch; }
        .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 11px; }
        @media (min-width: 768px) { .grid { grid-template-columns: repeat(3, 1fr); gap: 16px; } }
        @media (min-width: 1080px) { .grid { grid-template-columns: repeat(4, 1fr); gap: 20px; } }

        .ncard { display: block; width: 100%; padding: 0; text-align: left; cursor: pointer;
            background: #FFFFFF; border-radius: 0; overflow: hidden; border: 1px solid rgba(45, 45, 45, .12);
            box-shadow: 0 1px 2px rgba(45, 45, 45, .04);
            transition: transform .45s var(--ease), box-shadow .45s ease, border-color .3s ease; }
        .ncard:hover { transform: translateY(-5px); border-color: var(--gold);
            box-shadow: 0 22px 44px -18px rgba(45, 45, 45, .36); }
        .ncard.selected { border-color: var(--ink-2); box-shadow: 0 0 0 3px rgba(45, 45, 45, .1); }
        .ncard-imgwrap { position: relative; aspect-ratio: 3/4; overflow: hidden;
            background: repeating-linear-gradient(45deg, #F0F0F0 0 9px, rgba(45, 45, 45, .05) 9px 18px); }
        .ncard-img { width: 100%; height: 100%; object-fit: contain; display: block;
            transition: transform .9s var(--ease); transform-origin: center; }
        .ncard:hover .ncard-img { transform: scale(1.07); }
        .ncard-check { position: absolute; top: 10px; right: 10px; width: 23px; height: 23px; border-radius: 50%;
            background: var(--ink-2); display: flex; align-items: center; justify-content: center; }
        .ncard-meta { padding: 12px 13px 15px; }
        .ncard-brand { font-size: 8.5px; letter-spacing: .2em; color: var(--muted-2); text-transform: uppercase; }
        .ncard-name { font-family: 'Playfair Display', serif; font-size: 14.5px; color: var(--ink-2); margin: 5px 0 7px; line-height: 1.2; }
        .ncard-price { font-size: 11.5px; letter-spacing: .06em; color: #6B6B6B; }

        /* skeleton / states */
        .sk { border: 1px solid rgba(45, 45, 45, .08); border-radius: 0; overflow: hidden; background: #FFFFFF; }
        .sk-img { aspect-ratio: 3/4; }
        .shimmer { background: linear-gradient(100deg, #F0F0F0 30%, #FFFFFF 50%, #F0F0F0 70%);
            background-size: 300px 100%; animation: nshimmer 1.4s linear infinite; }
        .sk-line { height: 8px; border-radius: 0; margin: 10px 12px; }
        .state-box { border: 1px solid var(--line); border-radius: 0; padding: 60px 28px; text-align: center; }
        .state-box h3 { font-family: 'Playfair Display', serif; font-size: 21px; color: var(--ink-2); margin: 0; font-weight: 500; }
        .state-box p { font-weight: 300; font-size: 13px; line-height: 1.6; color: #6B6B6B; margin: 10px auto 0; max-width: 30ch; }
        .link-btn { background: none; border: none; cursor: pointer; font-family: 'Montserrat', sans-serif;
            font-size: 11px; letter-spacing: .2em; color: var(--gold-dark); text-transform: uppercase;
            border-bottom: 1px solid rgba(107, 107, 107, .5); padding: 0 0 2px; }

        /* ---- CTA footer ---- */
        .cta-footer { padding: 18px 22px 28px; }
        .cta-summary { display: none; font-size: 12.5px; letter-spacing: .03em; color: var(--muted); }
        @media (min-width: 768px) {
            .cta-footer { display: flex; align-items: center; justify-content: space-between; gap: 24px;
                padding: 22px 40px 30px; border-top: 1px solid rgba(45, 45, 45, .08); margin-top: 14px; }
            .cta-summary { display: inline; }
        }
        @media (min-width: 1080px) { .cta-footer { padding: 22px 72px 30px; } }
        .btn-primary { width: 100%; padding: 17px; border: none; border-radius: 0; cursor: pointer;
            font-family: 'Montserrat', sans-serif; font-size: 12px; font-weight: 500; letter-spacing: .22em; text-transform: uppercase;
            background: var(--ink-2); color: var(--surface);
            transition: transform .3s var(--ease), box-shadow .3s ease, background .3s ease, color .25s ease; }
        .btn-primary:hover { background: var(--ink); transform: translateY(-2px); box-shadow: 0 16px 32px -14px rgba(45, 45, 45, .5); }
        .btn-primary:active { transform: translateY(0) scale(.99); }
        .btn-primary:disabled { background: transparent; color: #6B6B6B; border: 1px solid rgba(45, 45, 45, .16); cursor: default; transform: none; box-shadow: none; }
        @media (min-width: 768px) { .cta-footer .btn-primary { width: auto; min-width: 240px; padding: 16px 44px; } }

        /* ---- Lead modal (bottom sheet on mobile, centered card on tablet+) ---- */
        .modal-backdrop { position: fixed; inset: 0; z-index: 50; display: flex; flex-direction: column; justify-content: flex-end; }
        @media (min-width: 768px) { .modal-backdrop { align-items: center; justify-content: center; padding: 40px; } }
        .modal-scrim { position: absolute; inset: 0; background: rgba(45, 45, 45, .5); backdrop-filter: blur(3px); animation: nfadein .3s ease; }
        .modal-card { position: relative; background: var(--surface); width: 100%;
            border-radius: 0; padding: 14px 24px 28px; animation: nsheet .42s cubic-bezier(.22, 1, .36, 1);
            box-shadow: 0 -10px 40px rgba(45, 45, 45, .2); }
        @media (min-width: 768px) {
            .modal-card { width: 460px; max-width: 100%; border-radius: 0; padding: 32px 36px 34px;
                animation: npop .4s cubic-bezier(.22, 1, .36, 1); box-shadow: 0 30px 70px rgba(45, 45, 45, .35); }
        }
        .modal-handle { width: 38px; height: 4px; border-radius: 0; background: rgba(45, 45, 45, .16); margin: 0 auto 20px; }
        @media (min-width: 768px) { .modal-handle { display: none; } }
        .modal-close { position: absolute; top: 18px; right: 20px; width: 28px; height: 28px; border-radius: 50%;
            border: 1px solid rgba(45, 45, 45, .14); background: none; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .modal-card h2 { font-family: 'Playfair Display', serif; font-weight: 500; font-size: 26px; line-height: 1.18;
            color: var(--ink-2); margin: 0 0 8px; letter-spacing: .005em; }
        .modal-card .lede { margin: 0 0 22px; max-width: none; }

        .channel-toggle { position: relative; display: flex; background: #F0F0F0; border-radius: 0; padding: 4px; margin-bottom: 14px; }
        .channel-toggle .pill { position: absolute; top: 4px; bottom: 4px; left: 4px; width: calc(50% - 4px);
            background: var(--surface); border-radius: 0; box-shadow: 0 1px 4px rgba(45, 45, 45, .14);
            transition: transform .4s cubic-bezier(.4, 0, .2, 1); }
        .channel-toggle .pill.email { transform: translateX(100%); }
        .channel-toggle button { flex: 1; position: relative; z-index: 1; display: flex; align-items: center; justify-content: center; gap: 7px;
            padding: 11px; border-radius: 0; cursor: pointer; border: none; background: transparent;
            font-family: 'Montserrat', sans-serif; font-size: 12.5px; letter-spacing: .04em; color: var(--muted-2);
            transition: color .3s ease, font-weight .3s ease; }
        .channel-toggle button.active { color: var(--ink-2); font-weight: 500; }
        .contact-row { display: flex; align-items: center; border: 1px solid rgba(45, 45, 45, .18); border-radius: 0;
            background: #FFFFFF; overflow: hidden; margin-bottom: 18px; }
        .contact-prefix { padding: 0 12px; font-size: 14px; color: #6B6B6B; border-right: 1px solid rgba(45, 45, 45, .14);
            height: 30px; display: flex; align-items: center; }
        .contact-input { flex: 1; border: none; outline: none; background: none; padding: 14px;
            font-family: 'Montserrat', sans-serif; font-size: 15px; color: var(--ink-2); }
        .modal-note { font-weight: 300; font-size: 11px; line-height: 1.5; color: var(--muted-2); text-align: center; margin: 13px 0 0; }
        /* Optional marketing opt-in, visually secondary to the required contact
           field — it is a separate permission, not part of the form's main path. */
        .marketing-row { display: flex; gap: 10px; align-items: flex-start; margin: 14px 0 4px; cursor: pointer; }
        .marketing-row input { flex: none; margin-top: 2px; width: 15px; height: 15px; accent-color: #2D2D2D; cursor: pointer; }
        .marketing-row span { font-weight: 300; font-size: 11.5px; line-height: 1.55; color: #6B6B6B; }
        .modal .error { color: var(--danger); font-size: 13px; margin: 8px 0 0; }

        /* ---- Generating overlay ---- */
        .gen-overlay { position: fixed; inset: 0; z-index: 60; display: flex; flex-direction: column;
            align-items: center; justify-content: center; padding: 40px; text-align: center;
            background: radial-gradient(120% 90% at 50% 30%, #FFFFFF 0%, #F0F0F0 100%); animation: nfadein .4s ease; }
        .gen-mark { position: relative; width: 104px; height: 104px; display: flex; align-items: center; justify-content: center; }
        .gen-ring { position: absolute; inset: 0; animation: nspin 2.4s linear infinite; }
        .gen-letter { font-family: 'Playfair Display', serif; font-size: 33px; color: var(--ink-2); letter-spacing: .04em; animation: npulse 2.2s ease-in-out infinite; }
        .gen-eyebrow { margin-top: 36px; font-size: 9.5px; letter-spacing: .34em; color: var(--gold); text-transform: uppercase; }
        .gen-step { margin-top: 13px; min-height: 34px; display: flex; align-items: center; justify-content: center;
            font-family: 'Playfair Display', serif; font-style: italic; font-size: 23px; color: var(--ink-2); animation: nfade .5s ease; }
        .gen-bar { margin-top: 32px; width: 200px; height: 1px; background: rgba(45, 45, 45, .12); overflow: hidden; }
        .gen-bar > span { display: block; height: 100%; background: var(--ink-2); animation: nprog 5.2s ease forwards; }

        /* ---- Generic full-screen status (failed / timeout) ---- */
        .status-overlay { position: fixed; inset: 0; z-index: 60; display: flex; flex-direction: column;
            align-items: center; justify-content: center; padding: 40px; text-align: center;
            background: radial-gradient(120% 90% at 50% 30%, #FFFFFF 0%, #F0F0F0 100%); animation: nfadein .4s ease; }
        .status-overlay h2 { font-family: 'Playfair Display', serif; font-weight: 500; font-size: 26px; color: var(--ink-2); margin: 0 0 10px; }
        .status-overlay p { font-weight: 300; font-size: 14px; line-height: 1.6; color: var(--muted); max-width: 32ch; margin: 0 0 22px; }

        /* ---- Result screen ---- */
        .result-overlay { position: fixed; inset: 0; z-index: 60; background: var(--ink);
            display: flex; flex-direction: column; animation: nfadein .5s ease; }
        .result-topbar { position: absolute; top: 0; left: 0; right: 0; z-index: 20; display: flex; align-items: center;
            justify-content: space-between; padding: 18px 20px; background: linear-gradient(rgba(45, 45, 45, .55), transparent); }
        .result-topbar .title { font-size: 9.5px; letter-spacing: .32em; color: rgba(255, 255, 255, .85); text-transform: uppercase; }
        .round-btn { width: 36px; height: 36px; border-radius: 50%; border: 1px solid rgba(255, 255, 255, .3);
            background: rgba(45, 45, 45, .25); backdrop-filter: blur(6px); display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: background .3s ease, border-color .3s ease, transform .3s ease; }
        .round-btn:hover { background: rgba(45, 45, 45, .5); border-color: rgba(255, 255, 255, .65); transform: scale(1.06); }
        .round-btn:active { transform: scale(.96); }
        .result-body { display: flex; flex-direction: column; flex: 1; min-height: 0; }
        @media (min-width: 768px) { .result-body { flex-direction: row; } }

        /* before/after slider — a centered stage. The box adopts the GENERATED
           look's own aspect-ratio (set inline from the loaded image) and the
           images use object-fit: contain, so a portrait FASHN result is shown
           WHOLE — never cropped — with no empty grey band. A max-height keeps a
           tall portrait inside the viewport on every screen. */
        .result-stage { flex: 1; min-height: 0; display: flex; align-items: center; justify-content: center;
            padding: 72px 18px 22px; }
        @media (min-width: 768px) { .result-stage { padding: 72px 28px 28px; } }
        #ba-slider { position: relative; width: 100%; max-width: 440px; max-height: 100%;
            overflow: hidden; border-radius: 0; touch-action: none; cursor: ew-resize; user-select: none;
            background: var(--ink); box-shadow: 0 30px 70px -30px rgba(0, 0, 0, .6); }
        @media (min-width: 1080px) { #ba-slider { max-width: 520px; } }
        .ba-before { position: absolute; inset: 0; }
        /* Whole-photo "Before" (no crop box) and the result both fill the SAME box;
           contain shows each image in full (no crop). The input is fitted to the
           engine's output ratio server-side (PhotoAspectFitter), so До already
           shares the frame with Після — no letterbox bars. */
        .ba-before-full, .ba-after img { width: 100%; height: 100%; object-fit: contain; object-position: center; display: block; }
        /* Synced "Before": the region wrapper is sized/positioned inline
           (beforeRegionStyle); the image inside is scaled/offset inline
           (beforeImgStyle) so the crop region fills it. */
        .ba-before-region img { display: block; }
        .ba-placeholder { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            background: repeating-linear-gradient(90deg, #2D2D2D 0 9px, rgba(255, 255, 255, .05) 9px 18px);
            font-size: 10px; letter-spacing: .26em; color: #6B6B6B; text-transform: uppercase; }
        .ba-after { position: absolute; inset: 0; }
        .ba-after img { animation: nreveal .85s var(--ease) both; }
        .ba-sweep { position: absolute; inset: 0; overflow: hidden; pointer-events: none; }
        .ba-sweep > span { position: absolute; inset: 0; background: linear-gradient(115deg, transparent 38%, rgba(255, 255, 255, .16) 50%, transparent 62%);
            transform: translateX(-135%); animation: nsweep 1.5s var(--ease) .35s both; }
        .ba-caption { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center;
            justify-content: flex-end; gap: 6px; padding: 0 0 40px; pointer-events: none;
            /* Bottom-up scrim: the caption sits at the bottom, so the tint has
               to be there and leave the look itself clear. Ported from a hand
               fix made straight on the server. */
            background: linear-gradient(to top, rgba(45, 45, 45, .55) 0%, transparent 45%);
            animation: nfadeup .55s var(--ease) both; animation-delay: .15s; }
        .ba-caption .eyebrow-light { font-size: 9.5px; letter-spacing: .32em; color: rgba(255, 255, 255, .85); text-transform: uppercase; }
        .ba-caption .look-name { font-family: 'Playfair Display', serif; font-style: italic; font-size: 18px; color: var(--surface); letter-spacing: .02em; }
        .ba-label { position: absolute; top: 14px; font-size: 9px; letter-spacing: .24em; text-transform: uppercase; color: rgba(255, 255, 255, .8); }
        .ba-label.before { left: 16px; }
        .ba-label.after { right: 16px; color: rgba(255, 255, 255, .9); }
        .ba-divider { position: absolute; top: 0; bottom: 0; width: 2px; background: rgba(255, 255, 255, .95); transform: translateX(-1px); }
        .ba-handle { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 42px; height: 42px;
            border-radius: 50%; background: rgba(255, 255, 255, .96); box-shadow: 0 4px 16px rgba(0, 0, 0, .3);
            display: flex; align-items: center; justify-content: center; }

        /* result rail */
        .rail { flex: none; background: linear-gradient(transparent, var(--ink) 22%); padding: 14px 20px 22px; }
        @media (min-width: 768px) {
            .rail { width: 340px; background: #2D2D2D; padding: 82px 30px 32px; display: flex; flex-direction: column;
                border-left: 1px solid rgba(255, 255, 255, .06); }
        }
        @media (min-width: 1080px) { .rail { width: 400px; } }
        #looks-row { display: flex; align-items: center; gap: 11px; margin-bottom: 18px; }
        #looks-row .looks-label { font-size: 9px; letter-spacing: .24em; color: rgba(255, 255, 255, .5); text-transform: uppercase; }
        .look-thumb { position: relative; width: 46px; height: 46px; border-radius: 0; cursor: pointer;
            background: repeating-linear-gradient(45deg, rgba(255, 255, 255, .16) 0 6px, rgba(255, 255, 255, .06) 6px 12px); border: 1.5px solid rgba(255, 255, 255, .18);
            overflow: hidden; transition: border-color .3s ease, transform .3s var(--ease); padding: 0; }
        .look-thumb img { width: 100%; height: 100%; object-fit: contain; }
        .look-skeleton { position: absolute; inset: 0; }
        .look-thumb.active { border-color: var(--surface); transform: scale(1.08); }
        .look-badge { position: absolute; bottom: 2px; right: 2px; font-size: 7px; letter-spacing: .1em; background: var(--surface);
            color: var(--ink); padding: 1px 3px; border-radius: 0; }
        .look-meta { margin: 16px 0 15px; animation: nfadeup .5s var(--ease) both; }
        @media (min-width: 768px) { .look-meta { margin-top: auto; margin-bottom: 22px; } }
        .look-meta .brand { font-size: 9px; letter-spacing: .24em; color: rgba(255, 255, 255, .6); text-transform: uppercase; margin-bottom: 7px; }
        .look-meta .name { font-family: 'Playfair Display', serif; font-size: 24px; color: var(--surface); line-height: 1.12; margin-bottom: 8px; }
        .look-meta .price { font-size: 15px; letter-spacing: .04em; color: #FFFFFF; }
        .btn-shop { display: block; width: 100%; padding: 17px; border: none; border-radius: 0; background: var(--surface);
            color: var(--ink-2); font-family: 'Montserrat', sans-serif; font-size: 12.5px; font-weight: 500; letter-spacing: .22em;
            text-transform: uppercase; text-align: center; text-decoration: none; cursor: pointer;
            transition: transform .35s var(--ease), box-shadow .35s ease, background .35s ease; }
        .btn-shop:hover { background: #FFFFFF; transform: translateY(-2px); box-shadow: 0 18px 36px -14px rgba(0, 0, 0, .5); }
        .btn-book { width: 100%; margin-top: 10px; padding: 16px; border: 1px solid rgba(255, 255, 255, .35); border-radius: 0;
            background: transparent; color: var(--surface); font-family: 'Montserrat', sans-serif; font-size: 11px; letter-spacing: .18em;
            text-transform: uppercase; cursor: pointer; transition: border-color .35s ease, background .35s ease, transform .35s var(--ease); }
        .btn-book:hover { border-color: rgba(255, 255, 255, .75); background: rgba(255, 255, 255, .07); transform: translateY(-1px); }
        .rail-note { margin-top: 12px; font-size: 11px; line-height: 1.5; color: rgba(255, 255, 255, .55); text-align: center; }

        /* ---- Share panel (desktop / no Web Share): download + copy link ---- */
        .share-panel { margin-top: 12px; border: 1px solid rgba(255, 255, 255, .18); border-radius: 0; padding: 14px; }
        .share-toggle { position: relative; display: flex; background: rgba(255, 255, 255, .08); border-radius: 0; padding: 3px; margin-bottom: 10px; }
        .share-toggle button { flex: 1; padding: 8px; border: none; border-radius: 0; background: transparent; cursor: pointer;
            font-family: 'Montserrat', sans-serif; font-size: 10.5px; letter-spacing: .14em; text-transform: uppercase; color: rgba(255, 255, 255, .6);
            transition: background .3s ease, color .3s ease; }
        .share-toggle button.active { background: var(--surface); color: var(--ink-2); }
        .share-actions { display: flex; gap: 8px; }
        .share-actions button { flex: 1; padding: 12px 8px; border: 1px solid rgba(255, 255, 255, .35); border-radius: 0;
            background: transparent; color: var(--surface); font-family: 'Montserrat', sans-serif; font-size: 10.5px; letter-spacing: .14em;
            text-transform: uppercase; cursor: pointer; transition: border-color .3s ease, background .3s ease; }
        .share-actions button:hover { border-color: rgba(255, 255, 255, .75); background: rgba(255, 255, 255, .07); }
    </style>
</head>
<body>
    <div class="app" x-data="fittingRoom()" x-init="init()">

        {{-- ===== Brand header ===== --}}
        <header class="brand-header">
            <img class="brand-logo" src="{{ asset('Logo_dark.svg') }}" alt="Atelier Fit" />
            <div class="brand-tag">Приватне ательє стилю · Дубай</div>
        </header>

        <div class="main-wrap">

            {{-- ============================================================
                 STEP ONE — Photo upload (REAL → private R2)
                 Dropzone mounts on #photo-dropzone; the real upload to
                 POST /api/fitting-room/photo returns a private key kept in
                 client state. No public URL is ever used.
                 ============================================================ --}}
            <div class="left-col">
                <div class="step-block">
                    <div class="eyebrow">Крок перший</div>
                    <h1 class="section-title">Завантажте ваше фото</h1>
                    <p class="lede">Завітайте до вашої приватної примірочної. Потрібне лише одне чітке, добре освітлене фото.</p>
                </div>

                {{-- Explicit consent, BEFORE the upload. Unticked by default and
                     never bundled with anything else: the file picker stays closed
                     until it is ticked, and the server refuses an upload without it
                     regardless of what the browser does. The wording and its version
                     live in config/consent.php (English — the language of the
                     public-facing interface), never inline here. --}}
                @if (config('consent.required'))
                <div class="consent" role="group" aria-labelledby="consent-label">
                    <label class="consent-row">
                        <input type="checkbox" id="photo-consent" x-model="consentAccepted">
                        <span id="consent-label">{{ config('consent.text') }}</span>
                    </label>
                    @if (config('consent.links.privacy_policy') || config('consent.links.ai_disclosure'))
                        <p class="consent-links">
                            @if (config('consent.links.privacy_policy'))
                                <a href="{{ config('consent.links.privacy_policy') }}" target="_blank" rel="noopener">Privacy Policy</a>
                            @endif
                            @if (config('consent.links.ai_disclosure'))
                                <a href="{{ config('consent.links.ai_disclosure') }}" target="_blank" rel="noopener">How your photo is processed by AI</a>
                            @endif
                        </p>
                    @endif

                    {{-- Acceptance of the Terms: its own box, ticked separately.
                         Bundled with the photo consent it would make that consent
                         unfree, and an unfree consent is worth nothing. --}}
                    <label class="consent-row consent-terms">
                        <input type="checkbox" id="terms-accept" x-model="termsAccepted">
                        <span>{{ config('terms.text') }}
                            <a href="{{ config('legal.base_url') }}/terms-of-use" target="_blank" rel="noopener">Read them</a>.
                        </span>
                    </label>
                </div>
                @endif

                <form id="photo-dropzone" class="dropzone" method="post"
                      action="/api/fitting-room/photo"
                      x-bind:class="consentAccepted && termsAccepted ? '' : 'consent-blocked'"
                      x-bind:aria-disabled="consentAccepted && termsAccepted ? 'false' : 'true'">
                    <div class="dz-message" data-dz-message>
                        <span class="camera-icon" aria-hidden="true">
                            <svg width="25" height="25" viewBox="0 0 24 24" fill="none"><path d="M5.5 9.5h2.2l1.1-1.8h6.4l1.1 1.8h2.2a1.6 1.6 0 011.6 1.6v6.3a1.6 1.6 0 01-1.6 1.6H5.5a1.6 1.6 0 01-1.6-1.6v-6.3a1.6 1.6 0 011.6-1.6z" stroke="#6B6B6B" stroke-width="1.1"/><circle cx="12" cy="14" r="3.1" stroke="#6B6B6B" stroke-width="1.1"/></svg>
                        </span>
                        <div class="dz-title">Перетягніть або натисніть, щоб обрати</div>
                        <div class="dz-sub">JPG або PNG · найкраще на повний зріст</div>
                    </div>
                    {{-- In-zone preview of the uploaded photo. Source is the SAME
                         client-side data URL (beforePreview) Dropzone produced in the
                         browser — never a server/public URL (privacy). Dropzone's own
                         tiny default thumbnail is hidden via CSS; its remove link
                         ("Прибрати") is kept and restyled. --}}
                    <div class="photo-preview" x-show="beforePreview" x-cloak>
                        <img x-bind:src="beforePreview" alt="Ваше завантажене фото">
                        {{-- Replace the current photo: clears it and reopens the
                             file picker in one click (does not rely on Dropzone's
                             built-in remove link, which is flaky in this v6 beta). --}}
                        <button type="button" class="replace-photo" x-on:click="replacePhoto()">Замінити фото</button>
                    </div>
                </form>
                <p class="photo-error" x-show="photoError" x-text="photoError" x-cloak></p>

                {{-- Privacy marker — honest copy. Photos go to a third-party (FASHN)
                     retained up to 72 hours; never claim instant deletion. --}}
                <div class="privacy" role="note">
                    <span class="lock" aria-hidden="true">
                        <svg width="17" height="17" viewBox="0 0 16 16" fill="none"><rect x="3.2" y="7" width="9.6" height="6.5" rx="1.6" stroke="#6B6B6B" stroke-width="1.1"/><path d="M5.4 7V5.2a2.6 2.6 0 015.2 0V7" stroke="#6B6B6B" stroke-width="1.1"/><circle cx="8" cy="10.2" r="1" fill="#6B6B6B"/></svg>
                    </span>
                    <div>
                        <div class="eyebrow">Приватно та захищено</div>
                        <p>
                            Ваше фото обробляється зовнішнім сервісом примірки та
                            <strong>видаляється протягом 72 годин</strong>.
                            Ми не зберігаємо його довше і не публікуємо.
                        </p>
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 STEP TWO — Catalog grid (REAL data from GET /api/catalog)
                 ============================================================ --}}
            <div class="right-col">
                <div class="catalog-head">
                    <div>
                        <div class="eyebrow">Крок другий</div>
                        <h2 class="section-title">Оберіть річ для примірки</h2>
                        {{-- Outfit hint: a set (e.g. низ + верх) can be tried on at
                             once, up to the configured cap. --}}
                        <p class="catalog-hint" x-show="maxOutfitItems > 1"
                           x-text="'Можна обрати до ' + maxOutfitItems + ' речей — приміряємо комплект разом.'" x-cloak></p>
                    </div>
                    <span class="catalog-count" x-show="state === 'ready'"
                          x-text="products.length + ' речей'" x-cloak></span>
                </div>

                {{-- loading --}}
                <div class="grid" x-show="state === 'loading'" x-cloak>
                    <template x-for="i in 8" :key="i">
                        <div class="sk">
                            <div class="sk-img shimmer"></div>
                            <div class="sk-line shimmer" style="width:40%"></div>
                            <div class="sk-line shimmer" style="width:75%"></div>
                            <div class="sk-line shimmer" style="width:30%"></div>
                        </div>
                    </template>
                </div>

                {{-- error --}}
                <div class="state-box" x-show="state === 'error'" x-cloak>
                    <h3>Не вдалося зв'язатися з ательє</h3>
                    <p>Щось перервало з'єднання. Спробуйте ще раз за мить.</p>
                    <p><button class="link-btn" type="button" x-on:click="load()">Спробувати ще раз</button></p>
                </div>

                {{-- empty --}}
                <div class="state-box" x-show="state === 'empty'" x-cloak>
                    <h3>Ательє поповнюється</h3>
                    <p>Зараз речей немає. Завітайте трохи пізніше — нові надходження вже в дорозі.</p>
                </div>

                {{-- ready --}}
                <div id="catalog-grid" class="grid" x-show="state === 'ready'" x-cloak>
                    <template x-for="product in products" :key="product.id">
                        <button class="ncard" type="button"
                                x-bind:class="{ selected: selectedIds.includes(product.id) }"
                                x-on:click="select(product.id)">
                            <div class="ncard-imgwrap">
                                <img class="ncard-img" x-bind:src="product.image_flat_url"
                                     x-bind:alt="product.product_name" loading="lazy">
                                <span class="ncard-check" x-show="selectedIds.includes(product.id)" x-cloak>
                                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2.5 6.2l2.2 2.2 4.6-4.8" stroke="#FFFFFF" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                            </div>
                            <div class="ncard-meta">
                                <div class="ncard-brand" x-text="product.brand_name"></div>
                                <div class="ncard-name" x-text="product.product_name"></div>
                                <div class="ncard-price" x-text="formatPrice(product.price_aed)"></div>
                            </div>
                        </button>
                    </template>
                </div>
            </div>
        </div>

        {{-- CTA footer — lead capture opens AFTER a photo AND an item are present. --}}
        <div class="cta-footer">
            <span class="cta-summary" x-text="ctaSummary" x-cloak></span>
            <button class="btn-primary" type="button"
                    x-bind:disabled="! canContinue"
                    x-on:click="openLeadModal()"
                    x-text="canContinue ? 'Продовжити' : 'Додайте фото та оберіть річ'">Продовжити</button>
        </div>

        {{-- ============================================================
             LEAD CAPTURE MODAL — POST /api/lead, then runs generation.
             ============================================================ --}}
        <div class="modal-backdrop" x-show="modalOpen && genState === 'lead'" x-cloak
             x-on:keydown.escape.window="closeLeadModal()">
            <div class="modal-scrim" x-on:click="closeLeadModal()"></div>
            <div id="lead-modal" class="modal-card" role="dialog" aria-modal="true" aria-labelledby="lead-title">
                <div class="modal-handle"></div>
                <button type="button" class="modal-close" x-on:click="closeLeadModal()" aria-label="Close">
                    <svg width="11" height="11" viewBox="0 0 11 11" fill="none"><path d="M1 1l9 9M10 1l-9 9" stroke="#6B6B6B" stroke-width="1.2" stroke-linecap="round"/></svg>
                </button>

                {{-- The contact is the condition for using the free service, and it
                     is where SERVICE messages about your try-on go. It is not "where
                     we send the result" — the result opens right here in the browser,
                     and saying otherwise dressed a lead capture up as a delivery
                     step. Marketing is asked for separately, below, and is optional. --}}
                <div class="eyebrow">Майже готово</div>
                <h2 id="lead-title">Ваш ексклюзивний образ уже створюється.</h2>
                <p class="lede">Залиште контакт — це умова доступу до безкоштовної примірки. Готовий образ відкриється тут, у браузері; на контакт надійдуть лише сервісні повідомлення про цю примірку.</p>

                <div class="channel-toggle">
                    <span class="pill" x-bind:class="{ email: channel === 'email' }"></span>
                    <button type="button" x-bind:class="{ active: channel === 'whatsapp' }" x-on:click="channel = 'whatsapp'">
                        <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M8 2.2A5.8 5.8 0 002.6 11l-.8 2.9 3-.8A5.8 5.8 0 108 2.2z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
                        WhatsApp
                    </button>
                    <button type="button" x-bind:class="{ active: channel === 'email' }" x-on:click="channel = 'email'">
                        <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><rect x="2" y="3.5" width="12" height="9" rx="1.4" stroke="currentColor" stroke-width="1.2"/><path d="M2.5 4.5L8 8.5l5.5-4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                        Email
                    </button>
                </div>

                <div class="contact-row">
                    <span class="contact-prefix" x-show="channel === 'whatsapp'" x-cloak>+971</span>
                    <input class="contact-input" x-model="contact"
                           x-bind:type="channel === 'email' ? 'email' : 'tel'"
                           x-bind:placeholder="channel === 'email' ? 'you@example.com' : '50 123 4567'">
                </div>

                {{-- Marketing opt-in: separate, optional, UNTICKED. Never a
                     condition of the try-on — refusing it changes nothing about the
                     service — and recorded on its own (marketing_consents) so the
                     two purposes stay apart. --}}
                <label class="marketing-row">
                    <input type="checkbox" x-model="marketingOptIn">
                    <span>{{ config('consent.marketing.text') }}</span>
                </label>

                <p class="error" x-show="errorMsg" x-text="errorMsg" x-cloak></p>

                <button type="button" class="btn-primary" x-bind:disabled="submitting" x-on:click="submitLead()">
                    <span x-show="! submitting">Отримати образ</span>
                    <span x-show="submitting" x-cloak>Надсилання…</span>
                </button>
                <p class="modal-note">Без згоди на промо контакт використовується лише для цієї примірки та сервісних повідомлень.</p>
            </div>
        </div>

        {{-- ============================================================
             GENERATING — honest loader. The step text comes from the server
             (loader_steps); the background line shows only when this
             generation actually restyles the background.
             ============================================================ --}}
        <div class="gen-overlay" x-show="genState === 'starting' || genState === 'processing' || genState === 'rendering'" x-cloak>
            <div class="gen-mark">
                <svg class="gen-ring" width="104" height="104" viewBox="0 0 96 96" fill="none"><circle cx="48" cy="48" r="44" stroke="rgba(107,107,107,.18)" stroke-width="1"/><path d="M48 4a44 44 0 0142 31" stroke="#6B6B6B" stroke-width="1.4" stroke-linecap="round"/></svg>
                <span class="gen-letter">N</span>
            </div>
            <div class="gen-eyebrow">Ваш стиліст працює</div>
            <div class="gen-step" id="loader-step" x-text="currentLoaderStep" aria-live="polite"></div>
            <div class="gen-bar"><span></span></div>
        </div>

        {{-- ============================================================
             RESULT SCREEN — shown when status = done. Generated look(s),
             a Before/After slider, and the action buttons. Rendered from
             client state we already have (polled result_urls + the selected
             product); no extra server call, no private data re-fetched.
             ============================================================ --}}
        <div class="result-overlay" id="result-screen" x-show="genState === 'done' && ! resultDismissed" x-cloak>
            <div class="result-topbar">
                <button type="button" class="round-btn" x-on:click="closeResult()" aria-label="Back">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M10 3l-5 5 5 5" stroke="#FFFFFF" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <div class="title">Ваш образ</div>
                {{-- Share — own result only. shareResult() shares the image bytes
                     (the user's own look) or falls back to the public page URL;
                     it never shares the signed status URL or a result URL. --}}
                <button type="button" class="round-btn" id="share-button" x-on:click="shareResult()" aria-label="Share">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><circle cx="12" cy="3.5" r="1.9" stroke="#FFFFFF" stroke-width="1.2"/><circle cx="4" cy="8" r="1.9" stroke="#FFFFFF" stroke-width="1.2"/><circle cx="12" cy="12.5" r="1.9" stroke="#FFFFFF" stroke-width="1.2"/><path d="M10.3 4.4L5.7 7.1M5.7 8.9l4.6 2.7" stroke="#FFFFFF" stroke-width="1.2"/></svg>
                </button>
            </div>

            <div class="result-body">
                {{-- Before/After slider. "Before" is the in-browser Dropzone
                     preview (a client-side data URL): the user's photo is never
                     re-uploaded, made public, or served from a predictable URL.
                     "After" is the selected generated look. Both layers share ONE
                     fixed-ratio portrait box (.result-stage centers it), so they
                     are the same size/crop — never two mismatched photos. --}}
                <div class="result-stage">
                    <div id="ba-slider"
                         x-bind:style="`aspect-ratio: ${resultRatio}`"
                         x-on:pointerdown="startSliderDrag($event)"
                         x-on:pointermove="onSliderMove($event)"
                         x-on:pointerup="stopSliderDrag()"
                         x-on:pointerleave="stopSliderDrag()">
                            {{-- "Before" / divider / labels appear ONLY when the
                             client-side preview exists. After a page reload that
                             preview is gone and we must NOT fetch the photo from the
                             server, so the slider degrades to result-only (the After
                             layer below fills the whole box). --}}
                        <div class="ba-before" x-show="beforeImage" x-cloak>
                            {{-- Preferred: the PROCESSED engine input the server
                                 returned (already oriented/lit/segmented/cropped). It
                                 is the exact image behind "Після", so showing it whole
                                 (contain) makes До/Після share one frame with no
                                 client-side crop maths. --}}
                            <template x-if="beforeUrl">
                                <img class="ba-before-full" x-bind:src="beforeUrl" alt="Before — your photo">
                            </template>
                            {{-- Fallback (no processed URL — e.g. an older response):
                                 the client preview, framed to the crop box when present
                                 so it still roughly matches the cropped result, else the
                                 whole uncropped photo. --}}
                            <template x-if="! beforeUrl && beforePreview && cropBox">
                                <div class="ba-before-region" x-bind:style="beforeRegionStyle()">
                                    <img x-bind:src="beforePreview" alt="Before — your photo" x-bind:style="beforeImgStyle()">
                                </div>
                            </template>
                            <template x-if="! beforeUrl && beforePreview && ! cropBox">
                                <img class="ba-before-full" x-bind:src="beforePreview" alt="Before — your photo">
                            </template>
                        </div>
                        <div class="ba-after"
                             x-bind:style="beforeImage ? `clip-path: inset(0 0 0 ${sliderPos}%)` : 'clip-path: inset(0)'">
                            <img x-bind:src="afterUrl" alt="After — your virtual try-on"
                                 x-on:load="setResultRatio($event.target)">
                            <div class="ba-sweep"><span></span></div>
                            <div class="ba-caption">
                                <span class="eyebrow-light">Результат примірки</span>
                                <span class="look-name" x-text="selectedProduct ? selectedProduct.product_name : 'Ваш образ'"></span>
                            </div>
                        </div>
                        <div class="ba-label before" x-show="beforeImage" x-cloak>До</div>
                        <div class="ba-label after" x-show="beforeImage" x-cloak>Після</div>
                        <div class="ba-divider" x-show="beforeImage" x-cloak x-bind:style="`left: ${sliderPos}%`">
                            <span class="ba-handle">
                                <svg width="17" height="17" viewBox="0 0 16 16" fill="none"><path d="M6 4L3 8l3 4M10 4l3 4-3 4" stroke="#6B6B6B" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="rail">
                    {{-- Multi-look (num_samples > 1): every look is selectable; a
                         badge marks looks that got a restyled background. --}}
                    <div id="looks-row" x-show="resultUrls.length > 1" x-cloak>
                        <span class="looks-label">Образи</span>
                        <template x-for="(look, i) in resultUrls" :key="i">
                            <button type="button" class="look-thumb"
                                    x-bind:class="{ active: activeLookIndex === i }"
                                    x-on:click="activeLookIndex = i">
                                <img x-bind:src="look.url" x-bind:alt="'Look ' + (i + 1)">
                                <span class="look-badge" x-show="look.with_background">BG</span>
                            </button>
                        </template>
                    </div>

                    {{-- SINGLE try-on: one item's meta + shop button. --}}
                    <div class="look-meta" x-show="! isOutfit && selectedProduct" x-cloak>
                        <div class="brand" x-text="selectedProduct ? selectedProduct.brand_name : ''"></div>
                        <div class="name" x-text="selectedProduct ? selectedProduct.product_name : ''"></div>
                        <div class="price" x-text="selectedProduct ? formatPrice(selectedProduct.price_aed) : ''"></div>
                    </div>

                    {{-- Shop — DYNAMIC from the chosen product (text = brand_name,
                         href = affiliate shop_url, new tab + noopener). A plain
                         link, no payment mechanics. --}}
                    <a class="btn-shop" id="shop-button"
                       x-show="! isOutfit && selectedProduct"
                       x-bind:href="selectedProduct ? selectedProduct.shop_url : '#'"
                       x-text="selectedProduct ? ('Купити на ' + selectedProduct.brand_name) : 'Купити цей образ'"
                       x-on:click="selectedProduct && track('shop_clicked', { product_id: selectedProduct.id })"
                       target="_blank" rel="noopener noreferrer"></a>

                    {{-- OUTFIT: the final image shows the whole set, so each garment
                         in it gets its own dynamic buy link (brand_name + shop_url
                         per item — never hardcoded). --}}
                    <div id="outfit-shop" x-show="isOutfit" x-cloak>
                        <div class="look-meta">
                            <div class="brand">Ваш комплект</div>
                            <div class="name" x-text="selectedProducts.length + ' речі'"></div>
                        </div>
                        <template x-for="item in selectedProducts" :key="item.id">
                            <a class="btn-shop" style="margin-bottom:10px"
                               x-bind:href="item.shop_url"
                               x-text="'Купити ' + item.product_name + ' — ' + item.brand_name"
                               x-on:click="track('shop_clicked', { product_id: item.id })"
                               target="_blank" rel="noopener noreferrer"></a>
                        </template>
                    </div>

                    {{-- Consultation — STUB ONLY. The real action (payment provider
                         or WhatsApp/ManyChat hand-off) is not chosen yet, so this is
                         a no-op that shows a "coming soon" note. Do NOT wire a
                         payment gateway or messenger vendor here. --}}
                    <button type="button" class="btn-book" id="consultation-button" x-on:click="bookConsultation()">
                        Консультація стиліста — $95
                    </button>

                    <p class="rail-note" x-show="consultationNote" x-cloak>
                        Консультації стиліста скоро будуть доступні — ми зв'яжемося за залишеним контактом.
                    </p>

                    {{-- Share panel — the desktop / no-Web-Share path: download the
                         branded card (feed by default, story via the toggle) or copy
                         a link TO THE SITE (with share UTM). The link is never a
                         signed URL and never a file URL; the card itself travels
                         only as a downloaded file the visitor sends themselves. --}}
                    <div class="share-panel" x-show="sharePanelOpen && hasShareCards" x-cloak>
                        <div class="share-toggle">
                            <button type="button" x-bind:class="{ active: shareFormat === 'feed' }" x-on:click="shareFormat = 'feed'">Стрічка 3:4</button>
                            <button type="button" x-bind:class="{ active: shareFormat === 'story' }" x-on:click="shareFormat = 'story'" x-show="shareUrls.story">Сторіз 9:16</button>
                        </div>
                        <div class="share-actions">
                            <button type="button" x-on:click="downloadCard()">Завантажити</button>
                            <button type="button" x-on:click="copyShareLink()">Скопіювати лінк</button>
                        </div>
                    </div>

                    <p class="rail-note" x-show="shareNote" x-text="shareNote" x-cloak></p>
                </div>
            </div>
        </div>

        {{-- ===== PHOTO NOT SUITABLE (soft nudge, not a crash) =====
             422 from /generate: the photo failed the suitability check. We show the
             server's localized reason (the same i18n message from 9.2) as a friendly
             hint and invite another photo — the chosen item and lead are kept. --}}
        <div class="status-overlay" x-show="genState === 'rejected'" x-cloak>
            <h2>Майже ідеально</h2>
            <p x-text="genMessage"></p>
            <button type="button" class="link-btn" x-on:click="retryPhoto()">Обрати інше фото</button>
        </div>

        {{-- ===== FAILED (clear message, no white screen, no internals) ===== --}}
        <div class="status-overlay" x-show="genState === 'failed'" x-cloak>
            <h2>Щось пішло не так</h2>
            <p class="error" x-text="genMessage"></p>
            {{-- Funnel CTA (Stage B3): free try-ons spent → sign in;
                 member credits spent → plans. Present only with a `code`d reject. --}}
            <a class="link-btn" x-cloak x-show="ctaUrl" x-bind:href="ctaUrl" x-text="ctaLabel" id="funnel-cta"></a>
            <button type="button" class="link-btn" x-on:click="dismissOverlay()">Закрити</button>
        </div>

        {{-- ===== TIMEOUT (polling gave up; the job may still finish) ===== --}}
        <div class="status-overlay" x-show="genState === 'timeout'" x-cloak>
            <h2>Зачекайте ще трохи</h2>
            <p>Генерація триває довше, ніж зазвичай. Ми надішлемо ваш образ на залишений контакт.</p>
            <button type="button" class="link-btn" x-on:click="dismissOverlay()">Закрити</button>
        </div>
    </div>

    {{-- No bundler: Alpine + Dropzone from a CDN, as in the existing page. --}}
    <script src="{{ asset('vendor/dropzone.min.js') }}"></script>
    {{-- Disable auto-discovery IMMEDIATELY, not inside the Alpine component.
         Dropzone attaches itself to every .dropzone element on DOMContentLoaded,
         and Alpine (deferred) may init after that — the upload form would then
         already carry an auto-created instance, our own `new Dropzone(...)`
         would throw "Dropzone already attached", and the rest of init() (result
         restore included) would never run. Turning it off here removes the race
         instead of relying on script timing. --}}
    <script>window.Dropzone && (Dropzone.autoDiscover = false);</script>
    <script defer src="{{ asset('vendor/alpine.min.js') }}"></script>

    <script>
        function fittingRoom() {
            return {
                // catalog
                state: 'loading',   // loading | error | empty | ready
                products: [],
                // Selected catalog items, in pick order. One = single try-on; two+
                // (up to maxOutfitItems) = an OUTFIT chain. The wearing order is
                // decided server-side by layer, not by this pick order.
                selectedIds: [],
                maxOutfitItems: {{ (int) config('fitting_room.outfit.max_items') }},
                // photo upload (real → R2) + lead modal
                photoAdded: false,
                photoKey: null,     // R2 key returned by the upload endpoint
                photoError: '',
                dz: null,           // Dropzone instance (for programmatic replace)
                // Client-side preview of the uploaded photo (a data URL produced by
                // Dropzone in the browser). Used for the upload-step thumbnail, and as
                // a fallback "Before" if the server didn't return a processed image.
                beforePreview: null,
                // Signed URL of the PROCESSED photo the engine actually received
                // (oriented, lit, segmented, cropped), returned by the generate
                // response. Preferred as the slider's "До" so it matches "Після"
                // exactly. Null until a generation starts / after a reload.
                beforeUrl: null,
                // Crop region (fractions of the photo) the server framed for this
                // generation: {x, y, width, height, aspect}. When set, the slider
                // shows "Before" framed to THIS exact region (the same one sent to
                // the engine) instead of the whole uncropped photo, so До/Після
                // share one frame. Null = no crop (fail-open skip / page reload) →
                // the whole-photo fallback. Client-session state only, like
                // beforePreview — never persisted, gone on reload.
                cropBox: null,
                modalOpen: false,
                channel: 'whatsapp',   // default WhatsApp — higher conversion in the UAE
                contact: '',
                // Marketing permission. Starts OFF and is never pre-filled: it is
                // optional, separate from the required contact, and refusing it
                // must not change anything about the service.
                marketingOptIn: false,
                submitting: false,
                errorMsg: '',
                // Stage B3: the logged-in member's owned lead {id, token}, minted
                // server-side (null for guests / flag off). With it the contact
                // modal is skipped — the contact was collected at signup — and
                // generations debit the member's credit ledger.
                memberLead: @json($memberLead ?? null),
                // Funnel CTA shown on the failed overlay (signup / paywall).
                ctaUrl: null,
                ctaLabel: '',

                // generation flow
                genState: 'lead',   // lead | starting | processing | done | rejected | failed | timeout
                generationId: null,
                statusUrl: null,
                withBackground: false,
                resultUrls: [],
                // Outfit progress, mirrored from the status poll ("item N of M").
                outfitTotal: 0,
                outfitStep: 0,
                // result screen
                resultDismissed: false,
                activeLookIndex: 0,
                // Aspect ratio of the actual generated look, learned from the
                // loaded image (naturalWidth/Height). The stage box adopts it so
                // a portrait FASHN result is shown WHOLE (object-fit: contain) with
                // no crop and no empty grey band. Sensible portrait default until
                // the first look loads.
                resultRatio: '3 / 4',
                sliderPos: 50,      // before/after divider, 0–100 (%)
                sliderDragging: false,
                // Consent state. Starts UNTICKED on every page load — never
                // remembered, never pre-filled: the visitor has to agree each time
                // before a photo can leave the device. With consent switched off
                // (config/consent.php) there is no box and nothing to wait for.
                consentAccepted: @json(! config('consent.required')),
                consentVersion: @json((string) config('consent.version')),
                // Acceptance of the Terms. Its own box, its own state.
                termsAccepted: @json(! config('consent.required')),
                termsVersion: @json((string) config('terms.version')),
                consentId: null,
                consultationNote: false,
                shareNote: '',
                // Branded share cards: signed (≤30 min) URLs from the status
                // poll — {feed, story}. Never persisted, never a public link.
                shareUrls: {},
                shareFormat: 'feed',
                sharePanelOpen: false,
                // Funnel timing/counters for first-party analytics events.
                genStartedAt: null,
                looksGeneratedCount: 0,
                loaderSteps: [],
                loaderIndex: 0,
                loaderTimer: null,
                pollTimer: null,
                pollAttempts: 0,
                genMessage: '',
                // Browser polling: every POLL_INTERVAL ms, give up after the poll
                // budget with a friendly message — never hang the tab. POLL_MAX is
                // the budget for ONE try-on (~2 min, matching the server's per-call
                // poll budget); an outfit runs N of those back-to-back, so the actual
                // budget is scaled by the layer count (see pollBudget).
                POLL_INTERVAL: 3000,
                POLL_MAX: 40,

                init() {
                    this.load();
                    this.initDropzone();
                    // Survive a page refresh: if the URL carries a validly signed
                    // generation, re-open it (result / loader / error) instead of
                    // the empty start screen.
                    this.restoreFromUrl();
                },

                get currentLoaderStep() {
                    // Outfit: an honest per-layer counter once the chain reports it.
                    if (this.outfitTotal > 1 && this.outfitStep > 0) {
                        return 'Приміряємо річ ' + this.outfitStep + ' з ' + this.outfitTotal + '…';
                    }
                    if (! this.loaderSteps.length) return 'Готуємо вашу примірку…';
                    return this.loaderSteps[this.loaderIndex % this.loaderSteps.length];
                },

                // The PRIMARY selected product (first pick) — source of the dynamic
                // shop button for a single try-on. Lives entirely in client state.
                get selectedProduct() {
                    return this.products.find((p) => p.id === this.selectedIds[0]) || null;
                },
                // Every selected product, in pick order — the outfit's buy buttons.
                get selectedProducts() {
                    return this.selectedIds
                        .map((id) => this.products.find((p) => p.id === id))
                        .filter(Boolean);
                },
                // Two or more picks = an outfit (chained) rather than a single try-on.
                get isOutfit() {
                    return this.selectedIds.length > 1;
                },
                // The look currently shown in the slider's "After" pane.
                get afterUrl() {
                    const look = this.resultUrls[this.activeLookIndex];
                    return look ? look.url : '';
                },
                // The slider's "Before" image: the processed engine input when we have
                // it (preferred — matches the result), otherwise the client preview.
                get beforeImage() {
                    return this.beforeUrl || this.beforePreview;
                },
                // Match the stage box to the real look proportions so the whole
                // image is shown (contain) with no crop and no grey filler. Falls
                // back to the portrait default if the dimensions aren't known yet.
                setResultRatio(img) {
                    if (img && img.naturalWidth && img.naturalHeight) {
                        this.resultRatio = img.naturalWidth + ' / ' + img.naturalHeight;
                    }
                },
                // Numeric width/height of the stage box (parsed from resultRatio,
                // a "W / H" string). Used to contain-fit the "Before" region.
                boxAspect() {
                    const parts = String(this.resultRatio).split('/');
                    const w = parseFloat(parts[0]), h = parseFloat(parts[1]);
                    return (w > 0 && h > 0) ? (w / h) : (3 / 4);
                },
                // Synced "Before": a wrapper whose aspect ratio EQUALS the crop
                // region's, contain-fit (centered) inside the stage box. Because the
                // wrapper matches the region's aspect, the image inside (sized by
                // beforeImgStyle) shows the region with NO distortion — only letterbox
                // bars around it, exactly mirroring how the engine framed the result.
                beforeRegionStyle() {
                    if (! this.cropBox) return '';
                    const a = this.cropBox.aspect, b = this.boxAspect();
                    // Region wider than the box -> bind to width; otherwise to height.
                    const fit = a >= b ? 'width:100%;height:auto;' : 'height:100%;width:auto;';
                    return 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);'
                        + 'overflow:hidden;aspect-ratio:' + a + ';' + fit;
                },
                // The "Before" photo scaled INSIDE the region wrapper so the crop
                // sub-rectangle exactly fills it. Percentages are relative to the
                // wrapper; object-fit:fill is safe (no distortion) because the wrapper
                // already carries the region's aspect ratio.
                beforeImgStyle() {
                    if (! this.cropBox) return '';
                    const c = this.cropBox;
                    return 'position:absolute;max-width:none;object-fit:fill;'
                        + 'width:' + (100 / c.width) + '%;'
                        + 'height:' + (100 / c.height) + '%;'
                        + 'left:' + (-100 * c.x / c.width) + '%;'
                        + 'top:' + (-100 * c.y / c.height) + '%;';
                },
                // A NON-private link safe to share: the site entrance, tagged as
                // share traffic. Never the signed status URL, never a result or
                // card URL. utm_medium stays "social" because the actual channel
                // the visitor picks in the system sheet is not knowable.
                get shareSiteUrl() {
                    return window.location.origin + '/?utm_source=share&utm_medium=social';
                },
                get hasShareCards() {
                    return !! (this.shareUrls && (this.shareUrls.feed || this.shareUrls.story));
                },
                // CTA helper copy.
                get ctaSummary() {
                    if (this.canContinue) {
                        if (this.isOutfit) return this.selectedIds.length + ' речі обрано — приміряємо комплект.';
                        return this.selectedProduct
                            ? this.selectedProduct.brand_name + ' · ' + this.selectedProduct.product_name + ' — обрано'
                            : '';
                    }
                    if (this.photoAdded) return 'Фото додано — тепер оберіть річ.';
                    if (this.selectedIds.length) return 'Річ обрано — тепер додайте фото.';
                    return 'Додайте фото та оберіть річ, щоб продовжити.';
                },

                // ZONE 1: photo upload is REAL — the file is uploaded to the
                // private R2 store via POST /api/fitting-room/photo. The endpoint
                // returns an internal key (photo_key) which we keep in state for
                // the generation step; we never receive or use a public URL.
                initDropzone() {
                    if (! window.Dropzone) return;
                    Dropzone.autoDiscover = false;
                    if (this.dz) return;      // already wired by this component
                    const el = document.querySelector('#photo-dropzone');
                    if (! el) return;
                    // Belt and braces: if anything attached an instance to the
                    // form before us, drop it rather than throwing — an
                    // auto-created one has none of the options below.
                    if (el.dropzone) el.dropzone.destroy();
                    const self = this;
                    this.dz = new Dropzone(el, {
                        url: '/api/fitting-room/photo',
                        paramName: 'photo',
                        method: 'post',
                        autoProcessQueue: true,   // really upload on drop/select
                        maxFiles: 1,
                        acceptedFiles: 'image/jpeg,image/png,image/webp',
                        maxFilesize: 12,          // MB; mirrors the server cap
                        addRemoveLinks: true,
                        // Ukrainian copy for Dropzone's built-in UI strings.
                        dictRemoveFile: 'Прибрати',
                        dictCancelUpload: 'Скасувати',
                        dictCancelUploadConfirmation: 'Скасувати завантаження?',
                        dictFileTooBig: 'Файл завеликий (@{{filesize}} МБ). Максимум: @{{maxFilesize}} МБ.',
                        dictInvalidFileType: 'Цей тип файлу не підтримується.',
                        dictResponseError: 'Помилка сервера (@{{statusCode}}).',
                        dictMaxFilesExceeded: 'Можна завантажити лише одне фото.',
                        // We render our OWN preview (.photo-preview / the slider's
                        // "Before" pane) from the ORIGINAL file, so Dropzone's own
                        // canvas thumbnail is not needed — and skipping it avoids any
                        // canvas resize/crop of the photo.
                        createImageThumbnails: false,
                        headers: { Accept: 'application/json' },
                        // Consent gate in the browser. The server refuses an upload
                        // without consent anyway; this stops the file from ever
                        // leaving the device in the first place.
                        accept(file, done) {
                            if (! self.consentAccepted || ! self.termsAccepted) {
                                done('consent-required');
                                return;
                            }
                            done();
                        },
                        init() {
                            // Consent travels with the upload: which wording was
                            // agreed to, and that it was agreed to at all.
                            this.on('sending', (file, xhr, formData) => {
                                formData.append('consent_accepted', '1');
                                formData.append('consent_version', self.consentVersion);
                                formData.append('terms_accepted', '1');
                                formData.append('terms_version', self.termsVersion);
                            });
                            this.on('success', (file, response) => {
                                self.photoKey = response.photo_key;
                                // Proof of consent, carried into the generate call.
                                self.consentId = response.consent_id;
                                self.photoAdded = true;
                                self.photoError = '';
                            });
                            this.on('error', (file, message) => {
                                self.photoError = message === 'consent-required'
                                    ? 'Спочатку підтвердьте згоду на обробку фото та прийміть Умови.'
                                    : 'Не вдалося завантажити фото. Спробуйте інше зображення.';
                            });
                            this.on('removedfile', () => {
                                if (this.files.length === 0) {
                                    self.photoAdded = false;
                                    self.photoKey = null;
                                    // The consent RECORD stays (it is the proof); only
                                    // this client-side handle to it is dropped, so the
                                    // next photo carries its own fresh one.
                                    self.consentId = null;
                                    self.beforePreview = null;
                                    self.beforeUrl = null;
                                    self.cropBox = null;   // crop is per-photo — drop the stale region
                                }
                            });
                            // Only one photo at a time — replace the previous. Build
                            // the "Before" preview from the ORIGINAL file bytes via
                            // FileReader: a full-resolution, UNCROPPED data URL with
                            // the photo's real aspect ratio (no canvas resize, so the
                            // head/feet are never cut). Purely client-side — the file
                            // is never re-fetched from the server or any public URL.
                            this.on('addedfile', (file) => {
                                if (this.files.length > 1) this.removeFile(this.files[0]);
                                self.cropBox = null;   // a new photo invalidates any prior crop region
                                self.beforeUrl = null; // and any prior processed "before" image
                                const reader = new FileReader();
                                reader.onload = (event) => { self.beforePreview = event.target.result; };
                                reader.readAsDataURL(file);
                            });
                        },
                    });
                },

                // Replace the current photo in one click: clear the existing file
                // (which resets photoKey/preview via the removedfile handler) and
                // reopen the OS file picker. Uses Dropzone's own hidden input so it
                // works regardless of the v6-beta remove-link quirks.
                replacePhoto() {
                    if (! this.dz) return;
                    this.dz.removeAllFiles(true);
                    const input = this.dz.hiddenFileInput
                        || document.querySelector('#photo-dropzone .dz-hidden-input')
                        || document.querySelector('.dz-hidden-input');
                    if (input) input.click();
                },

                // ZONE 2: load the real catalog from the public API endpoint.
                async load() {
                    this.state = 'loading';
                    try {
                        const response = await fetch('/api/catalog', { headers: { Accept: 'application/json' } });
                        if (! response.ok) throw new Error('HTTP ' + response.status);
                        const body = await response.json();
                        this.products = body.data || [];
                        this.state = this.products.length ? 'ready' : 'empty';
                    } catch (error) {
                        this.state = 'error';
                    }
                },
                // Toggle an item in/out of the selection. Capped at maxOutfitItems —
                // when full, the earliest pick makes way for the new one, so a tap
                // always selects what was tapped (no silent dead click).
                select(id) {
                    const i = this.selectedIds.indexOf(id);
                    if (i >= 0) {
                        this.selectedIds.splice(i, 1);
                        return;
                    }
                    if (this.selectedIds.length >= this.maxOutfitItems) {
                        this.selectedIds.shift();
                    }
                    this.selectedIds.push(id);
                    this.track('item_selected', { product_id: id });
                },

                // First-party analytics ping to OUR server (no vendor script, no
                // cookie of its own). Fire-and-forget: an analytics hiccup must
                // never surface in the UI. keepalive lets shop_clicked survive
                // the navigation it rides on.
                track(event, props = {}) {
                    try {
                        fetch('/api/analytics/event', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                            body: JSON.stringify({ event, props }),
                            keepalive: true,
                        }).catch(() => {});
                    } catch (error) { /* never break the page over analytics */ }
                },
                formatPrice(value) {
                    return value == null ? '' : value + ' AED';
                },

                // Lead can only be collected after a photo AND at least one item.
                get canContinue() {
                    return this.photoAdded && this.selectedIds.length >= 1;
                },
                openLeadModal() {
                    if (! this.canContinue) return;
                    // A member's contact is already on file (signup) — straight to
                    // generation, no modal. Lead timing stays honest: contact was
                    // collected before any result either way.
                    if (this.memberLead) {
                        this.startGeneration(this.memberLead.id, this.memberLead.token);
                        return;
                    }
                    this.errorMsg = '';
                    this.genState = 'lead';
                    this.modalOpen = true;
                },
                closeLeadModal() {
                    this.modalOpen = false;
                    this.stopTimers();
                },

                // STEP A: capture the lead, then start generation right after.
                async submitLead() {
                    this.errorMsg = '';
                    this.submitting = true;
                    try {
                        const response = await fetch('/api/lead', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                            body: JSON.stringify({
                                channel: this.channel,
                                contact: this.contact,
                                marketing_opt_in: this.marketingOptIn,
                            }),
                        });
                        if (response.status === 422) { this.errorMsg = 'Перевірте правильність контакту.'; return; }
                        if (response.status === 429) { this.errorMsg = 'Забагато спроб. Спробуйте трохи пізніше.'; return; }
                        if (! response.ok) throw new Error('HTTP ' + response.status);
                        const body = await response.json();
                        // Lead captured → kick off the real generation.
                        await this.startGeneration(body.lead_id);
                    } catch (error) {
                        this.errorMsg = 'Не вдалося надіслати. Спробуйте ще раз.';
                    } finally {
                        this.submitting = false;
                    }
                },

                // STEP B: POST the protected /generate endpoint (lead gate / cap /
                // per-user limit live there) and begin polling the signed status URL.
                async startGeneration(leadId, leadToken = null) {
                    this.modalOpen = false;   // close the lead modal; the loader overlay takes over
                    this.genState = 'starting';
                    this.outfitStep = 0;
                    this.outfitTotal = 0;
                    this.ctaUrl = null;
                    this.ctaLabel = '';
                    this.shareUrls = {};
                    this.sharePanelOpen = false;
                    this.shareNote = '';
                    this.genStartedAt = Date.now();
                    try {
                        // One item → single try-on; two+ → the outfit chain endpoint.
                        const outfit = this.isOutfit;
                        const url = outfit ? '/api/fitting-room/generate-outfit' : '/api/fitting-room/generate';
                        const payload = outfit
                            ? { lead_id: leadId, product_ids: this.selectedIds, photo_key: this.photoKey, consent_id: this.consentId }
                            : { lead_id: leadId, product_id: this.selectedIds[0], photo_key: this.photoKey, consent_id: this.consentId };
                        if (leadToken) payload.lead_token = leadToken;
                        const response = await fetch(url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                            body: JSON.stringify(payload),
                        });
                        // Funnel gates (Stage B3): out of free try-ons → sign-in
                        // CTA; out of member credits → plans CTA. Both carry a
                        // machine `code` — the message alone is for humans.
                        if (response.status === 403 || response.status === 402) {
                            const body = await response.json().catch(() => ({}));
                            if (body.code === 'signup_required') {
                                this.ctaUrl = '{{ route('login') }}';
                                this.ctaLabel = 'Увійти';
                            } else if (body.code === 'credits_exhausted') {
                                this.ctaUrl = '{{ route('membership.pricing') }}';
                                this.ctaLabel = 'Обрати план';
                            }
                            return this.failGeneration(body.message || 'Не вдалося розпочати примірку. Спробуйте ще раз.');
                        }
                        // Error responses from /generate carry a localized body.message
                        // (the gate's limit/cap text, or the photo-suitability reason
                        // from preprocessing). Show THAT to the user, not a generic line.
                        if (response.status === 429) { return this.failGeneration(await this.errorMessage(response, 'Ви вичерпали денний ліміт примірок. Спробуйте завтра.')); }
                        if (response.status === 503) { return this.failGeneration(await this.errorMessage(response, 'Сервіс примірки тимчасово недоступний. Спробуйте пізніше.')); }
                        // 422 = the photo did not pass the suitability check. A soft,
                        // friendly nudge to try another photo — NOT a crash — keeping
                        // the selected item and the captured lead.
                        if (response.status === 422) { return this.rejectPhoto(await this.errorMessage(response, 'Це фото не підійшло. Спробуйте інше — на повний зріст і обличчям до камери.')); }
                        if (! response.ok) throw new Error('HTTP ' + response.status);

                        const body = await response.json();
                        this.generationId = body.generation_id;
                        this.statusUrl = body.status_url;
                        this.withBackground = !! body.with_background;
                        // The region the photo was cropped to (or null). Drives the
                        // fallback "Before" framing in the slider.
                        this.cropBox = body.crop_box || null;
                        // The processed photo the engine received — the preferred "До".
                        this.beforeUrl = body.before_url || null;
                        // Outfit: total layers, so the loader can show "item N of M".
                        this.outfitTotal = (body.outfit && body.outfit.total) || 0;
                        // Reflect the signed generation in the address bar so a
                        // refresh during processing/result survives (same signature
                        // that already guards this generation — not a public link).
                        this.reflectResultUrl();
                        this.genState = 'processing';
                        this.startLoaderRotation();
                        this.pollAttempts = 0;
                        this.pollStatus();
                    } catch (error) {
                        this.failGeneration('Не вдалося розпочати примірку. Спробуйте ще раз.');
                    }
                },

                // The poll budget for THIS generation: one try-on's worth of attempts
                // per layer. An outfit chains N sequential engine calls, so the page
                // must wait ~N× as long as a single try-on before giving up —
                // otherwise it times out while the chain is still legitimately running.
                get pollBudget() {
                    return this.POLL_MAX * Math.max(1, this.outfitTotal);
                },

                // STEP B→C/D/E: poll the signed status URL until done/failed, or
                // give up after the (layer-scaled) poll budget (timeout).
                async pollStatus() {
                    if (this.genState !== 'processing') return;
                    if (this.pollAttempts >= this.pollBudget) { this.timeoutGeneration(); return; }
                    this.pollAttempts++;

                    try {
                        const response = await fetch(this.statusUrl, { headers: { Accept: 'application/json' } });
                        if (! response.ok) throw new Error('HTTP ' + response.status);
                        const body = await response.json();

                        // The honest loader steps are authored by the server (the
                        // background step is present ONLY when this generation runs it).
                        if (Array.isArray(body.loader_steps) && body.loader_steps.length) {
                            this.loaderSteps = body.loader_steps;
                        }

                        // Outfit chains report live progress ("item N of M") so the
                        // loader counter is honest about which layer is going on now.
                        if (body.outfit) {
                            this.outfitTotal = body.outfit.total || this.outfitTotal;
                            this.outfitStep = body.outfit.step || 0;
                        }

                        if (body.status === 'done') {
                            this.resultUrls = body.result_urls || [];
                            this.shareUrls = body.share || {};
                            this.activeLookIndex = 0;
                            this.sliderPos = 50;
                            this.resultDismissed = false;
                            this.modalOpen = false;   // close the lead modal; the result overlay takes over
                            this.genState = 'done';
                            this.stopTimers();
                            this.looksGeneratedCount++;
                            const viewProps = { mode: this.isOutfit ? 'outfit' : 'single' };
                            if (this.genStartedAt) viewProps.time_to_view_ms = Date.now() - this.genStartedAt;
                            this.track('result_viewed', viewProps);
                            return;
                        }
                        if (body.status === 'failed') { this.failGeneration(body.message || 'На жаль, не вдалося створити образ.'); return; }
                        // queued / processing → keep polling.
                        this.pollTimer = setTimeout(() => this.pollStatus(), this.POLL_INTERVAL);
                    } catch (error) {
                        // Transient fetch error — retry within the same attempt budget.
                        this.pollTimer = setTimeout(() => this.pollStatus(), this.POLL_INTERVAL);
                    }
                },

                startLoaderRotation() {
                    this.loaderIndex = 0;
                    this.loaderTimer = setInterval(() => { this.loaderIndex++; }, this.POLL_INTERVAL);
                },
                failGeneration(message) {
                    this.genState = 'failed';
                    this.genMessage = message;
                    this.stopTimers();
                },
                // A soft photo rejection (HTTP 422 from preprocessing): NOT a crash.
                // Show the server's localized reason and let the visitor choose
                // another photo without losing the selected item or captured lead.
                rejectPhoto(message) {
                    this.genState = 'rejected';
                    this.genMessage = message;
                    this.photoError = message;   // also surfaced beside the dropzone on return
                    this.stopTimers();
                },
                // Return from a soft rejection to the start screen to pick another
                // photo. Item selection and the lead are intentionally preserved.
                retryPhoto() {
                    this.track('regenerate_clicked', { reason: 'new_photo' });
                    this.genState = 'lead';
                    this.modalOpen = false;
                    this.stopTimers();
                    this.clearResultUrl();
                },
                // Read a localized message from an error response body, falling back
                // to a generic line when the body carries none.
                async errorMessage(response, fallback) {
                    try {
                        const body = await response.json();
                        if (body && typeof body.message === 'string' && body.message.trim() !== '') {
                            return body.message;
                        }
                    } catch (error) { /* non-JSON / empty body — use the fallback */ }
                    return fallback;
                },
                timeoutGeneration() {
                    this.genState = 'timeout';
                    this.stopTimers();
                },
                stopTimers() {
                    if (this.loaderTimer) { clearInterval(this.loaderTimer); this.loaderTimer = null; }
                    if (this.pollTimer) { clearTimeout(this.pollTimer); this.pollTimer = null; }
                },

                // ---- Result screen ----

                closeResult() {
                    this.resultDismissed = true;
                    this.sharePanelOpen = false;
                    this.shareNote = '';
                    // Returning to the start screen: drop the signed params so a
                    // refresh now shows the start, not the dismissed result.
                    this.clearResultUrl();
                },
                // Dismiss a failed/timeout overlay and return to the main screen.
                dismissOverlay() {
                    this.genState = 'lead';
                    this.modalOpen = false;
                    this.resultDismissed = false;
                    this.stopTimers();
                    this.clearResultUrl();
                },

                // ---- Refresh-safe restore (signed) ----

                // Mirror the signed status URL into the address bar as
                // ?generation={id}&expires=…&signature=… — the SAME params that
                // already authorise this generation. This is NOT a shareable/public
                // link; it only lets the same user survive a refresh.
                reflectResultUrl() {
                    if (! this.statusUrl || ! this.generationId) return;
                    try {
                        const u = new URL(this.statusUrl, window.location.origin);
                        const params = new URLSearchParams();
                        params.set('generation', this.generationId);
                        for (const [k, v] of u.searchParams) params.set(k, v); // expires, signature
                        history.replaceState({}, '', window.location.pathname + '?' + params.toString());
                    } catch (error) { /* address-bar update is best-effort */ }
                },
                clearResultUrl() {
                    try { history.replaceState({}, '', window.location.pathname); } catch (error) {}
                },

                // On load: if the URL carries a signed generation, rebuild the EXACT
                // signed status endpoint URL and ask the server. The signature is
                // enforced server-side, so a missing/forged/foreign/expired signature
                // returns 403/404 → we soft-reset to the start screen (no leak, no
                // 500, no id enumeration). The "Before" photo is NOT refetched.
                async restoreFromUrl() {
                    const params = new URLSearchParams(window.location.search);
                    const id = params.get('generation');
                    if (! id) return;
                    params.delete('generation');                 // leaves expires + signature
                    const signed = params.toString();
                    if (! signed) { this.clearResultUrl(); return; }

                    const statusUrl = '/api/fitting-room/generation/' + encodeURIComponent(id) + '?' + signed;
                    try {
                        const response = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                        if (! response.ok) { this.clearResultUrl(); return; }   // invalid/foreign/expired → soft reset
                        const body = await response.json();

                        this.generationId = id;
                        this.statusUrl = statusUrl;
                        if (Array.isArray(body.loader_steps)) this.loaderSteps = body.loader_steps;
                        // beforePreview stays null here — the client preview is gone
                        // after a reload and we never fetch the photo from the server,
                        // so the slider shows result-only.

                        if (body.status === 'done') {
                            this.resultUrls = body.result_urls || [];
                            this.shareUrls = body.share || {};
                            this.activeLookIndex = 0;
                            this.sliderPos = 50;
                            this.resultDismissed = false;
                            this.genState = 'done';
                            return;
                        }
                        if (body.status === 'failed') { this.failGeneration(body.message || 'На жаль, не вдалося створити образ.'); return; }
                        // queued / processing → resume the loader and polling.
                        this.genState = 'processing';
                        this.startLoaderRotation();
                        this.pollAttempts = 0;
                        this.pollStatus();
                    } catch (error) {
                        this.clearResultUrl();
                    }
                },

                // Before/After divider drag (mouse + touch via pointer events).
                startSliderDrag(event) {
                    this.sliderDragging = true;
                    this.updateSliderFrom(event);
                },
                onSliderMove(event) {
                    if (this.sliderDragging) this.updateSliderFrom(event);
                },
                stopSliderDrag() {
                    this.sliderDragging = false;
                },
                updateSliderFrom(event) {
                    const rect = event.currentTarget.getBoundingClientRect();
                    const x = event.clientX - rect.left;
                    const pct = (x / rect.width) * 100;
                    this.sliderPos = Math.min(100, Math.max(0, pct));
                },

                // Consultation — STUB. No payment provider / messenger vendor is
                // chosen yet, so this is intentionally a no-op that just surfaces a
                // "coming soon" note. The real hand-off is wired in a later step.
                bookConsultation() {
                    this.consultationNote = true;
                    this.track('consult_clicked', { looks_generated_count: this.looksGeneratedCount });
                },

                // Share the user's OWN result as the BRANDED CARD (Этап B2).
                // Mobile: Web Share API with the card file → the system sheet;
                // the file goes only where the visitor sends it. Anything else:
                // the panel with «Завантажити» + «Скопіювати лінк». What is
                // shared as a LINK is always the public site with share UTM —
                // never the signed status URL, never a result/card URL.
                async shareResult() {
                    // One-line honesty note, on screen BEFORE the sheet opens.
                    this.shareNote = 'Ви ділитеся зображенням, на якому — ви. Ми нічого не публікуємо: файл піде лише туди, куди ви його надішлете.';
                    const title = 'Моя віртуальна примірка';
                    const text = 'Подивіться мій образ із віртуальної примірочної! ' + this.shareSiteUrl;
                    const format = this.shareUrls[this.shareFormat] ? this.shareFormat : 'feed';
                    const cardUrl = this.shareUrls[format] || this.afterUrl;
                    try {
                        if (cardUrl && navigator.canShare) {
                            try {
                                const resp = await fetch(cardUrl);
                                const blob = await resp.blob();
                                const file = new File([blob], 'my-look-' + format + '.jpg', { type: blob.type || 'image/jpeg' });
                                if (navigator.canShare({ files: [file] })) {
                                    await navigator.share({ files: [file], title, text });
                                    this.track('result_shared', { format, method: 'web_share' });
                                    return;
                                }
                            } catch (imgError) {
                                // Card fetch/file share unavailable — fall through.
                            }
                        }
                        if (this.hasShareCards) {
                            // No file sharing here (desktop): open the panel.
                            this.sharePanelOpen = ! this.sharePanelOpen;
                            return;
                        }
                        if (navigator.share) {
                            // No card composed — at least share the public link.
                            await navigator.share({ title, text: 'Подивіться мій образ із віртуальної примірочної!', url: this.shareSiteUrl });
                            this.track('result_shared', { format, method: 'web_share' });
                            return;
                        }
                        await this.copyShareLink();
                    } catch (error) {
                        // User cancelled or sharing failed — no event, no crash.
                    }
                },
                // Download the branded card (panel action). Blob round-trip so the
                // download attribute works on the cross-origin signed URL.
                async downloadCard() {
                    const format = this.shareUrls[this.shareFormat] ? this.shareFormat : 'feed';
                    const url = this.shareUrls[format];
                    if (! url) return;
                    try {
                        const resp = await fetch(url);
                        const blob = await resp.blob();
                        const link = document.createElement('a');
                        link.href = URL.createObjectURL(blob);
                        link.download = 'my-look-' + format + '.jpg';
                        document.body.appendChild(link);
                        link.click();
                        link.remove();
                        setTimeout(() => URL.revokeObjectURL(link.href), 5000);
                        this.shareNote = 'Картку збережено. Ми нічого не публікуємо — вона лише у вас.';
                        this.track('result_shared', { format, method: 'download' });
                    } catch (error) {
                        this.shareNote = 'Не вдалося завантажити картку. Спробуйте ще раз.';
                    }
                },
                // Copy the PUBLIC site link (with share UTM) — panel action.
                async copyShareLink() {
                    const format = this.shareUrls[this.shareFormat] ? this.shareFormat : 'feed';
                    try {
                        await navigator.clipboard.writeText(this.shareSiteUrl);
                        this.shareNote = 'Посилання скопійовано — поділіться примірочною з подругами!';
                        this.track('result_shared', { format, method: 'copy_link' });
                    } catch (error) {
                        this.shareNote = 'Поділіться сторінкою: ' + this.shareSiteUrl;
                    }
                },
            };
        }
    </script>
</body>
</html>

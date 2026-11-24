@extends('membership.shell')

@section('title', __('dashboard.title'))

{{-- Account dashboard, built from the Figma "Desk_lk" frame (node 1388:6440)
     and updated to its successor "Main" (node 1661:6500), which adds the
     replace-photo button, the photo tips, "згенерувати повторно" and the
     catalog card's heart + "Обрати" button:
     try-on workspace (photo / result / catalog), share strip, "buy this look",
     the consultation block, three lead cards and the plan grid.

     Palette: the frame's extra greys (its borders, the light card surfaces,
     the soft text on dark) are rendered with the four brand colors at
     reduced alpha — see the tokens in layout.blade.php. Headlines are
     Prata (the frame's face; Playfair Display is the fallback), body is Montserrat Light
     standing in for HelveticaNeueCyr Light, which is not a web font we can
     self-host. --}}

@section('styles')
        /* Page primitives (.dl, .dl-head, .dl-btn, .compare) live in layout.blade.php. */
        .depleted-row { margin-top: 18px; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 14px 18px;
            border: 1px solid rgba(166, 67, 46, .25); background: rgba(166, 67, 46, .05);
            cursor: pointer; width: 100%; text-align: start; font-family: 'Montserrat', sans-serif; }
        .depleted-row .msg { font-size: 14px; color: var(--danger); flex: 1; min-width: 14rem; }
        .depleted-row .cta { font-size: 11px; letter-spacing: .14em; color: var(--danger); text-transform: uppercase; white-space: nowrap; }

        /* ---- Try-on workspace: photo | result | catalog ---- */
        /* minmax(0, …): a panel must never widen the page for its own content
           (the mobile card strip scrolls inside its panel instead). */
        .tryon-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; margin-top: 40px; }
        @media (min-width: 834px) { .tryon-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1200px) { .tryon-grid { grid-template-columns: minmax(0, 378fr) minmax(0, 512fr) minmax(0, 646fr); } }
        .panel { min-width: 0; border: 1px solid var(--line-soft); padding: 12px; background: var(--surface); }
        .panel-label { font-size: 16px; line-height: 1.5; color: var(--ink-2); margin: 0 0 12px; }
        .frame-2x3 { position: relative; aspect-ratio: 2 / 3; overflow: hidden; background: var(--surface-2); min-height: 251px; }
        .frame-2x3 img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; }
        .frame-2x3.is-over { outline: 2px dashed var(--ink-2); outline-offset: -2px; }
        /* Dropzone / "result appears here" empty states (LK_mob 1432:7860, 1432:8259) */
        .dropzone { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center;
            justify-content: center; gap: 12px; padding: 24px 40px; text-align: center;
            background: var(--surface-2); border: 1px dashed var(--line-soft); }
        .dropzone .mark { flex: none; width: 32px; height: 32px; border-radius: 50%; border: 1px solid var(--ink-2);
            display: flex; align-items: center; justify-content: center; margin-bottom: 8px; }
        .dropzone p { margin: 0; font-size: 14px; line-height: 1.5; max-width: 252px; }
        .dropzone .dz-title { font-weight: 400; text-transform: uppercase; color: var(--ink-2); }
        .dropzone .dz-hint { color: var(--muted); }
        .dropzone .dz-btn { margin-top: 8px; min-height: 45px; padding: 12px 28px; font-size: 14px; letter-spacing: .13em; white-space: nowrap; }
        .dropzone .dz-formats { font-size: 12px; color: var(--muted); }

        /* "Замінити фото" under a chosen photo (Main 1661:6954) */
        .replace-btn { margin-top: 15px; }

        /* What a good photo looks like (Main 1661:6534) */
        /* Tips and the privacy note sit a shade lighter than --surface-2:
           the frame's F6F6F6 grey, ink at 4% on white. */
        .photo-tips { list-style: none; margin: 25px 0 0; padding: 14px 15px; display: flex; flex-direction: column; gap: 8px;
            background: rgba(45, 45, 45, .04); }
        .photo-tips li { display: flex; align-items: center; gap: 12px; font-size: 14px; line-height: 1.5; color: var(--muted); }
        .photo-tips svg { flex: none; }

        .safe-note { display: flex; gap: 12px; align-items: center; margin-top: 12px; padding: 12px 15px;
            background: rgba(45, 45, 45, .04); }
        .safe-note svg { flex: none; }
        .safe-note p { margin: 0; font-size: 14px; line-height: 1.5; color: var(--muted); }

        /* Consent to the photo and the Terms: two boxes, unticked, before any
           upload — the server refuses a photo without both. */
        .tryon-consent { margin-top: 12px; display: flex; flex-direction: column; gap: 10px; }
        .consent-row { display: flex; gap: 10px; align-items: flex-start; cursor: pointer; }
        .consent-row input { flex: none; width: 18px; height: 18px; margin: 1px 0 0; accent-color: #2D2D2D; }
        .consent-row span { font-size: 12.5px; line-height: 1.5; color: var(--muted); }
        .consent-row a, .consent-links a { color: var(--ink-2); }
        .consent-links { margin: -4px 0 0 28px; font-size: 12px; line-height: 1.5; color: var(--muted); display: flex; gap: 14px; flex-wrap: wrap; }

        /* Result frame while a look is being made, and when it was not */
        .gen-state { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center;
            justify-content: center; gap: 12px; padding: 24px 32px; text-align: center; background: var(--surface-2); }
        .gen-state p { margin: 0; font-size: 14px; line-height: 1.5; max-width: 260px; }
        .gen-state .dz-title { font-weight: 400; text-transform: uppercase; color: var(--ink-2); }
        .gen-state .dz-hint { color: var(--muted); }
        .gen-state .retry { margin-top: 8px; }
        /* Loader (mobile frame "Створюємо твій образ"): heading, ring, the
           pipeline's steps ticked off, a photo tip. */
        .gen-loader { position: absolute; inset: 0; z-index: 2; display: flex; flex-direction: column; align-items: center;
            gap: 28px; padding: 32px 24px 24px; background: var(--surface-2); color: var(--ink-2); text-align: center; overflow-y: auto; }
        .gen-loader-full { position: fixed; z-index: 60; padding: 72px 24px calc(24px + env(safe-area-inset-bottom)); }
        html.gen-lock { overflow: hidden; }
        @media (min-width: 834px) { html.gen-lock { overflow: auto; } }
        .gl-title { margin: 0; font-family: var(--serif); font-weight: 400; font-size: 26px; line-height: 1.3; }
        .gl-sub { margin: 10px 0 0; font-size: 14px; line-height: 1.5; color: var(--muted); }
        .gl-ring { position: relative; flex: none; width: 140px; height: 140px; display: flex; align-items: center; justify-content: center; }
        .gl-spin { position: absolute; inset: 0; width: 100%; height: 100%; animation: glspin 1.4s linear infinite; }
        @keyframes glspin { to { transform: rotate(360deg); } }
        .gl-steps { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 14px;
            align-self: stretch; text-align: start; font-size: 14px; line-height: 1.4; }
        .gl-steps li { display: flex; align-items: center; gap: 12px; color: rgba(45, 45, 45, .45); transition: color .25s ease; }
        .gl-steps li.done, .gl-steps li.active { color: var(--ink-2); }
        .gl-dot { flex: none; width: 22px; height: 22px; border-radius: 50%; border: 1.5px solid rgba(45, 45, 45, .25);
            display: flex; align-items: center; justify-content: center; color: var(--surface); }
        .gl-steps li.done .gl-dot { background: var(--ink-2); border-color: var(--ink-2); }
        .gl-steps li.active .gl-dot { border-color: rgba(45, 45, 45, .2); border-top-color: var(--ink-2); animation: glspin 1s linear infinite; }
        .gl-tip { margin-top: auto; align-self: stretch; display: flex; gap: 14px; padding: 16px 18px; border-radius: 0;
            background: rgba(45, 45, 45, .04); text-align: start; }
        .gl-tip svg { flex: none; margin-top: 2px; }
        .gl-tip p { margin: 0; font-size: 13px; line-height: 1.5; color: var(--muted); }
        .gl-tip .gl-tip-title { margin-bottom: 4px; font-size: 15px; font-weight: 500; color: var(--ink-2); }
        /* In the desktop result panel: the same loader, tighter, so the tip fits the frame. */
        .gen-loader:not(.gen-loader-full) { gap: 18px; padding: 24px 20px 20px; }
        .gen-loader:not(.gen-loader-full) .gl-title { font-size: 22px; }
        .gen-loader:not(.gen-loader-full) .gl-ring { width: 104px; height: 104px; }
        .gen-loader:not(.gen-loader-full) .gl-steps { gap: 10px; font-size: 13px; }
        .gen-loader:not(.gen-loader-full) .gl-tip { padding: 12px 14px; }
        .gen-loader:not(.gen-loader-full) .gl-tip p { font-size: 12px; }
        @media (prefers-reduced-motion: reduce) { .gl-spin, .gl-steps li.active .gl-dot { animation: none; } }
        .working-bar { width: 160px; height: 3px; border-radius: 0; margin-bottom: 8px;
            background: linear-gradient(90deg, rgba(45, 45, 45, .08) 0, rgba(45, 45, 45, .35) 50%, rgba(45, 45, 45, .08) 100%);
            background-size: 320px 100%; animation: nshimmer 1.6s linear infinite; }
        @media (prefers-reduced-motion: reduce) { .working-bar { animation: none; background: rgba(45, 45, 45, .2); } }
        .generate-btn { margin-top: 12px; }
        .result-actions { display: flex; flex-direction: column; gap: 12px; margin-top: 12px; }

        /* "Обрана річ" (Figma 1893:9143): the look's piece in the catalog's
           place — its column from 1200px, the full row under photo and result
           on a tablet. The phone has its own result block. */
        .chosen-panel { display: none; }
        @media (min-width: 834px) {
            .chosen-panel { display: block; }
            .tryon-grid.has-look #catalog-panel, .tryon-grid.has-look #result-actions { display: none; }
        }
        @media (min-width: 834px) and (max-width: 1199px) { .chosen-panel { grid-column: 1 / -1; } }
        .chosen-box { border: 1px solid var(--line-soft); padding: 8px; }
        .chosen-card { display: flex; gap: 19px; }
        .chosen-card .pic { flex: none; width: 176px; aspect-ratio: 176 / 245; background: var(--surface); overflow: hidden; }
        /* The whole piece, never a crop of its middle: a long dress is taller than the frame */
        .chosen-card .pic img { display: block; width: 100%; height: 100%; object-fit: contain; }
        .chosen-card .text { min-width: 0; display: flex; flex-direction: column; gap: 8px; }
        .chosen-card p { margin: 0; }
        .chosen-card .brand { font-size: 14px; line-height: 1.5; text-transform: uppercase; color: var(--muted);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .chosen-card .name { font-family: var(--serif); font-size: 16px; line-height: 1.4; color: var(--ink-2); }
        .chosen-card .price { font-size: 16px; line-height: 1.5; color: var(--muted); }
        .chosen-fav { display: inline-flex; align-items: center; gap: 8px; align-self: flex-start; padding: 0; border: none; background: none;
            cursor: pointer; font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 16px; line-height: 1.5; color: var(--muted); }
        .chosen-fav svg { color: var(--ink-2); }
        .chosen-fav.liked svg path { fill: currentColor; }
        .chosen-fav:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
        .chosen-panel .result-actions { margin-top: 12px; gap: 8px; }
        .chosen-panel .result-actions .dl-btn { min-height: 54px; }
        /* Four tiles, the frame's F4F4F4 (ink at 5%), 12px corners */
        .chosen-tools { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 13px; margin-top: 16px; }
        .chosen-tool { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; min-height: 75px;
            padding: 9px 4px; border: none; border-radius: 12px; background: rgba(45, 45, 45, .05); cursor: pointer;
            color: var(--ink-2); text-decoration: none; font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 16px; line-height: 1.5;
            transition: background .25s ease; }
        .chosen-tool:hover, .chosen-tool[aria-pressed="true"] { background: rgba(45, 45, 45, .1); }
        .chosen-tool:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
        .chosen-note { margin: 8px 0 0; font-size: 12.5px; color: var(--muted); text-align: center; }
        .chosen-details { margin-top: 16px; }
        .chosen-details-toggle { display: flex; align-items: center; justify-content: space-between; width: 100%; padding: 0 0 2px;
            border: none; background: none; cursor: pointer; font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 16px;
            line-height: 1.5; color: var(--muted); }
        .chosen-details-toggle svg { color: var(--ink-2); transition: transform .25s var(--ease); }
        .chosen-details-toggle svg.open { transform: rotate(180deg); }
        .chosen-details-toggle:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
        .chosen-desc { margin: 8px 0 0; font-size: 14px; line-height: 1.5; color: var(--ink-2); white-space: pre-line; }
        /* Under the result: the same photo and piece once more */
        .regen-btn { margin-top: 12px; min-height: 56px; }
        /* 1200–1599: the three columns are ~200–450px, so step 3's buttons,
           the piece's picture and the tiles are set smaller to stay one line. */
        @media (min-width: 1200px) and (max-width: 1599px) {
            .tryon-grid .dl-btn { padding-inline-start: 10px; padding-inline-end: 10px; font-size: 13px; letter-spacing: .08em; }
            .chosen-panel .result-actions .dl-btn, .regen-btn { min-height: 46px; }
            .chosen-card { gap: 12px; }
            .chosen-card .pic { width: 120px; }
            .chosen-card .brand { font-size: 12px; }
            .chosen-card .name { font-size: 14px; }
            .chosen-card .price, .chosen-fav, .chosen-details-toggle { font-size: 14px; }
            .chosen-tools { gap: 8px; }
            .chosen-tool { min-height: 64px; font-size: 13px; }
            .zoom-bar { gap: 8px; padding: 6px 8px; }
            .zoom-btn { width: 30px; height: 30px; }
            .zoom-val { min-width: 36px; font-size: 13px; }
        }

        /* ---- Step 3 below the workspace (Figma 1893:9938, 1906:11245, 1901:11218) ---- */
        .look-extras { display: flex; flex-direction: column; gap: 24px; margin-top: 24px; }
        .look-extras h2 { font-family: var(--serif); font-weight: 400; margin: 0; color: var(--ink-2); }
        .cmp-strip { display: flex; flex-wrap: wrap; align-items: center; gap: 16px 40px; padding: 12px 16px 12px 39px; border: 1px solid var(--line-soft); }
        .cmp-title h2 { font-size: 22px; line-height: 41.6px; }
        .cmp-count { margin: 15px 0 0; font-size: 16px; line-height: 1.5; color: var(--muted); }
        .cmp-count b { font-weight: 500; }
        .cmp-looks { flex: 1; display: flex; flex-wrap: wrap; gap: 32px; padding-top: 6px; }
        .cmp-look { position: relative; width: 126px; height: 126px; }
        .cmp-pick { display: block; width: 100%; height: 100%; padding: 0; border: none; border-radius: 4px; overflow: hidden;
            background: rgba(45, 45, 45, .04); cursor: pointer; }
        .cmp-pick img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .cmp-look.is-picked .cmp-pick { box-shadow: 0 0 0 2px var(--ink-2); }
        .cmp-pick:focus-visible, .cmp-remove:focus-visible, .cmp-add:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
        .cmp-remove { position: absolute; top: -6px; inset-inline-end: -12px; width: 32px; height: 32px; padding: 0; border: none; background: none;
            border-radius: 50%; cursor: pointer; }
        .cmp-add { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; width: 126px; height: 126px;
            padding: 0 8px; border: 1px solid rgba(45, 45, 45, .6); border-radius: 4px; background: none; cursor: pointer; color: var(--ink-2);
            font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 16px; line-height: normal; letter-spacing: .13em; text-transform: uppercase; }
        .cmp-add:hover { background: rgba(45, 45, 45, .04); }
        .cmp-go { flex: none; margin-inline-start: auto; }
        /* Pieces that go with the look: 134px picture, info, "Купити" + heart */
        .pair-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px; }
        .pair-card { display: flex; gap: 9px; min-width: 0; }
        .pair-card .pic { flex: none; width: 134px; height: 134px; padding: 0; border: none; border-radius: 4px; overflow: hidden;
            background: rgba(45, 45, 45, .04); cursor: zoom-in; }
        .pair-card .pic img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .pair-card .pic:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
        .pair-card .info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 8px; }
        .pair-card p { margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pair-card .brand { font-size: 12px; line-height: 1.5; text-transform: uppercase; color: var(--muted); }
        .pair-card .name { font-family: var(--serif); font-size: 16px; line-height: normal; color: var(--ink-2); }
        .pair-card .price { font-size: 16px; line-height: 1.5; color: var(--muted); }
        .pair-card .buttons { display: flex; gap: 6px; margin-top: auto; }
        .pair-shop { flex: 1; display: flex; align-items: center; justify-content: center; height: 32px; border-radius: 4px;
            background: var(--ink-2); color: var(--surface); text-decoration: none; font-size: 9px; letter-spacing: .13em; text-transform: uppercase; }
        .pair-like { flex: none; display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; padding: 0;
            border: 1px solid rgba(45, 45, 45, .6); border-radius: 4px; background: none; cursor: pointer; color: var(--ink-2); }
        .pair-like.liked svg path { fill: currentColor; }
        .pair-shop:focus-visible, .pair-like:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
        /* The stylist: the stylist's cut-out, the question, the button */
        .ask-strip { display: flex; align-items: center; gap: 24px 40px; min-height: 150px; padding: 0 16px 0 39px; border: 1px solid var(--line-soft); }
        .ask-pic { flex: none; align-self: flex-end; width: 120px; height: 145px; object-fit: cover; object-position: 50% 0; transform: scaleX(-1); }
        .ask-text { flex: 1; min-width: 0; padding: 20px 0; }
        .ask-text h2 { font-size: 38px; line-height: normal; }
        .ask-text p { margin: 15px 0 0; font-size: 22px; line-height: 1.5; color: var(--muted); }
        .ask-strip .dl-btn { flex: none; }
        @media (min-width: 1600px) { .ask-strip { padding-inline-start: 133px; column-gap: 122px; } }
        @media (max-width: 1599px) {
            .ask-text h2 { font-size: 28px; }
            .ask-text p { font-size: 17px; }
        }
        @media (max-width: 1199px) {
            .cmp-strip { padding-inline-start: 16px; }
            .cmp-looks { flex-basis: 100%; order: 3; }
            .ask-strip { padding-inline-start: 16px; gap: 20px; }
            .ask-pic { width: 96px; height: 116px; }
        }
        .generate-note { margin: 8px 0 0; font-size: 13px; line-height: 1.5; color: var(--muted); text-align: center; }

        /* Catalog panel */
        /* One height for the search and the brand field: the box sets it, the
           input brings no padding of its own (a search input has 1px). */
        .catalog-field { display: flex; align-items: center; justify-content: space-between; gap: 10px; min-height: 43px;
            border: 1px solid var(--line-soft); border-radius: 0; padding: 8px 12px; margin-bottom: 8px; background: var(--surface); }
        .catalog-field:focus-within { border-color: var(--ink-2); }
        .catalog-field input { flex: 1; min-width: 0; border: none; outline: none; background: none;
            margin: 0; padding: 0; font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 16px; line-height: 1.5; color: var(--ink-2);
            appearance: none; -webkit-appearance: none; }
        .catalog-field svg { flex: none; }

        /* Brand picker (searchable multi-select) */
        .brand-picker { position: relative; }
        .brand-trigger { cursor: pointer; }
        .brand-trigger:focus-visible { outline: none; border-color: var(--ink-2); box-shadow: 0 0 0 3px rgba(45, 45, 45, .12); }
        .brand-trigger.open { border-color: var(--ink-2); }
        .brand-trigger .caret { transition: transform .25s var(--ease); }
        .brand-trigger.open .caret { transform: rotate(180deg); }
        .brand-value { flex: 1; min-width: 0; display: flex; flex-wrap: wrap; align-items: center; gap: 6px;
            font-size: 16px; line-height: 1.5; color: var(--ink-2); }
        .brand-value .placeholder { color: rgba(45, 45, 45, .5); }
        .brand-chip { display: inline-flex; align-items: center; gap: 6px; padding: 2px 6px 2px 10px;
            border: 1px solid rgba(45, 45, 45, .2); border-radius: 0; font-size: 13px; line-height: 1.5; color: var(--ink-2); }
        .brand-chip .remove { display: flex; align-items: center; justify-content: center; width: 18px; height: 18px;
            border: none; border-radius: 50%; background: none; color: var(--muted); cursor: pointer; padding: 0; }
        .brand-chip .remove:hover { background: var(--ink-2); color: var(--surface); }
        .brand-menu { position: absolute; z-index: 30; top: calc(100% - 4px); inset-inline-start: 0; inset-inline-end: 0; background: var(--surface);
            border: 1px solid var(--line-soft); border-radius: 0; overflow: hidden;
            box-shadow: 0 24px 50px -22px rgba(45, 45, 45, .45); animation: npop .22s cubic-bezier(.22, 1, .36, 1); }
        .brand-menu .search { width: 100%; padding: 13px 15px; border: none; outline: none; background: none;
            border-bottom: 1px solid var(--line-soft); font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 15px; color: var(--ink-2); }
        .brand-menu .list { max-height: 264px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: #2D2D2D rgba(45, 45, 45, .12); }
        .brand-option { display: flex; align-items: center; gap: 11px; width: 100%; padding: 10px 15px; border: none;
            background: none; cursor: pointer; text-align: start; font-family: 'Montserrat', sans-serif; font-weight: 300;
            font-size: 15px; color: var(--ink-2); }
        .brand-option:hover, .brand-option.active { background: var(--surface-2); }
        .brand-option .tick { flex: none; width: 18px; height: 18px; border-radius: 50%; border: 1px solid var(--ink-2);
            display: flex; align-items: center; justify-content: center; }
        .brand-option.active .tick { background: var(--ink-2); }
        .brand-option .label { flex: 1; }
        .brand-option .count { font-size: 12.5px; color: var(--muted); }
        .brand-menu .empty { margin: 0; padding: 16px 15px; font-size: 13px; color: var(--muted); }
        .brand-menu .foot { display: flex; justify-content: flex-end; gap: 18px; padding: 10px 15px;
            border-top: 1px solid var(--line-soft); }
        .chips { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0 14px; }
        .chip { padding: 8px 12px; border: 1px solid var(--line-soft); border-radius: 0; background: none; cursor: pointer;
            font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 15px; line-height: 1.5; color: var(--ink-2);
            transition: background .25s ease, color .25s ease, border-color .25s ease; }
        .chip:hover { border-color: var(--ink-2); }
        .chip.active { background: var(--ink-2); border-color: var(--ink-2); color: var(--surface); }
        /* Chips that do not fit the one row go under "Ще" (chipRow) */
        .chip[hidden], .chips-more[hidden] { display: none; }
        .chips-more { position: relative; }
        .chips-more .chip { display: inline-flex; align-items: center; gap: 6px; }
        .chips-more .brand-menu { top: calc(100% + 4px); inset-inline-start: auto; inset-inline-end: 0; min-width: 220px; }
        /* Two pieces a row by default; on a tablet and up the row takes as
           many ~150px cards as the panel fits (three on the 1920px frame). */
        .pick-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; max-height: 720px;
            overflow-y: auto; padding-inline-end: 8px; scrollbar-width: thin; scrollbar-color: #2D2D2D rgba(45, 45, 45, .12); }
        /* Card as drawn in Main (1661:6542): heart top-right, "Обрати" button at the foot */
        .pick-card { position: relative; display: flex; flex-direction: column; aspect-ratio: 197 / 273;
            border: 1px solid var(--ink-2); border-radius: 0; background: var(--surface); padding: 12px 8px 8px;
            cursor: pointer; text-align: center; font-family: 'Montserrat', sans-serif; font-weight: 300;
            transition: box-shadow .3s ease, transform .3s var(--ease); }
        .pick-card:hover { transform: translateY(-2px); box-shadow: 0 14px 28px -18px rgba(45, 45, 45, .5); }
        .pick-card.selected { box-shadow: 0 0 0 1px var(--ink-2); }
        .pick-card .like { position: absolute; top: 12px; inset-inline-end: 8px; width: 28px; height: 28px; padding: 0; border: none;
            background: none; cursor: pointer; color: rgba(45, 45, 45, .25); transition: color .25s ease, transform .25s var(--ease); }
        .pick-card .like:hover { transform: scale(1.08); }
        .pick-card .like.liked { color: var(--ink-2); }
        .pick-card .like:focus-visible, .pick-card .pick:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
        .pick-card .pick { margin-top: 10px; width: 100%; padding: 8px 12px; border: 1px solid var(--ink-2); border-radius: 0;
            background: transparent; color: var(--ink-2); cursor: pointer; font-family: 'Montserrat', sans-serif; font-weight: 300;
            font-size: 16px; line-height: normal; letter-spacing: .13em; text-transform: uppercase;
            transition: background .25s ease, color .25s ease; }
        /* Outline until picked, filled once picked ("Обрано") */
        .pick-card.selected .pick { background: var(--ink-2); color: var(--surface); }
        /* The picture is a button: it opens the detail modal ("Детальніше") */
        .pick-card .pic { flex: 1; min-height: 0; display: flex; align-items: center; justify-content: center; margin: 22px 0 8px;
            width: 100%; padding: 0; border: none; background: none; cursor: zoom-in; }
        .pick-card .pic:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
        .pick-card .pic img { max-height: 100%; max-width: 70%; object-fit: contain; }
        .pick-card .brand { font-size: 9.5px; line-height: 1.5; letter-spacing: .04em; text-transform: uppercase; color: var(--muted);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        /* One line, cut with an ellipsis: a second line comes out of the picture's height */
        .pick-card .name { font-family: var(--serif); font-size: 12.7px; line-height: 1.3; color: var(--ink-2); margin: 5px 0;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pick-card .price { font-size: 12.7px; line-height: 1.5; color: var(--muted); }
        .pick-empty { grid-column: 1 / -1; padding: 48px 20px; text-align: center; font-size: 14px; line-height: 1.6; color: var(--muted);
            border: 1px dashed var(--line-soft); }

        /* ---- Tablet and desktop, Figma 1893:9423 ("Приміряти образ / з каталогу") ----
           Drawn at 1920px. From 1200px the three columns keep its 378 : 512 : 646
           split at one height, the catalog scrolling inside its own; on a
           tablet photo and result share a row and the catalog runs full width
           under them. The phone (Figma 1872:8187) is styled further down. */
        @media (min-width: 834px) {
            .tryon-head { margin-bottom: 45px; }
            .tryon-grid { margin-top: 0; }
            .panel-label { font-family: var(--serif); font-size: 22px; line-height: 23px; }
            /* Empty photo and result: 50px ring, 16px title, the frame's F6F6F6
               (ink at 4%); the result's is striped, its button in the middle. */
            .frame-2x3 { background: var(--surface); }
            .dropzone { gap: 12px; background: rgba(45, 45, 45, .04); }
            .dropzone .mark { width: 50px; height: 50px; margin-bottom: 8px; }
            .dropzone .mark svg { width: 24px; height: 24px; }
            .dropzone .dz-title { font-size: 16px; }
            .dropzone .dz-btn { margin-top: 21px; min-height: 0; padding: 16px 23px; font-size: 16px; letter-spacing: .13em; }
            .dropzone .dz-formats, .dropzone .generate-note { margin: 0; font-size: 14px; }
            #result-panel .dropzone { background: repeating-linear-gradient(45deg, rgba(45, 45, 45, .04) 0 20px, rgba(45, 45, 45, .07) 20px 40px); }
            .photo-tips { margin-top: 12px; }
            .safe-note { padding: 16px 15px 20px; }

            /* Zoom under a finished look: − 100% + | full screen */
            .zoom-bar { position: absolute; z-index: 4; left: 50%; bottom: 18px; transform: translateX(-50%);
                display: flex; align-items: center; gap: 16px; padding: 8px 12px; border: 1px solid rgba(45, 45, 45, .08);
                border-radius: 4px; background: var(--surface); }
            .zoom-btn { flex: none; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; padding: 0;
                border: 1px solid rgba(45, 45, 45, .12); border-radius: 4px; background: var(--surface); color: var(--ink-2); cursor: pointer; }
            .zoom-btn:disabled { opacity: .4; cursor: default; }
            .zoom-btn:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
            .zoom-val { min-width: 40px; font-size: 14px; color: var(--muted); text-align: center; }
            .zoom-sep { width: 1px; height: 20px; background: rgba(45, 45, 45, .12); }
            #result-panel .compare { transform: scale(var(--zoom, 1)); transition: transform .25s var(--ease); }
            #result-panel .frame-2x3:fullscreen { background: var(--surface); }
            #result-panel .frame-2x3:fullscreen img { object-fit: contain; }

            /* Catalog: 4px corners on the fields and chips, 16px chips, cards
               197 x 237 with 14px corners — the pick is the ring top-right, the
               heart moves top-left — and the frame's 6px dark scrollbar. */
            .catalog-field, .chip { border-radius: 4px; }
            .chip { font-size: 16px; }
            .pick-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 14px 11px; padding-inline-end: 12px;
                scrollbar-width: auto; scrollbar-color: auto; }
            .pick-grid::-webkit-scrollbar { width: 6px; }
            .pick-grid::-webkit-scrollbar-track { background: rgba(45, 45, 45, .18); }
            .pick-grid::-webkit-scrollbar-thumb { background: var(--ink-2); }
            .pick-card { aspect-ratio: 197 / 237; border-radius: 14px; padding: 34px 9px 18px; }
            .pick-card .pic { margin: 0 0 12px; }
            .pick-card .like { top: 10px; inset-inline-start: 12px; inset-inline-end: auto; width: 26px; height: 26px; }
            .pick-card .like svg { width: 20px; height: 20px; }
            .pick-card .pick { position: absolute; top: 12px; inset-inline-end: 16px; width: 22px; height: 22px; margin: 0; padding: 0;
                border-radius: 50%; font-size: 0; letter-spacing: 0; overflow: hidden; }
            .pick-card .pick::after { content: ''; position: absolute; left: 7px; top: 3.5px; width: 5px; height: 10px;
                border: solid var(--surface); border-width: 0 1.5px 1.5px 0; transform: rotate(45deg); opacity: 0; }
            .pick-card.selected .pick::after { opacity: 1; }
        }
        @supports not selector(::-webkit-scrollbar) {
            @media (min-width: 834px) { .pick-grid { scrollbar-width: thin; scrollbar-color: #2D2D2D rgba(45, 45, 45, .18); } }
        }
        /* Tablet: the catalog takes the whole row under photo and result. */
        @media (min-width: 834px) and (max-width: 1199px) {
            #catalog-panel { grid-column: 1 / -1; }
        }
        /* Three columns: the catalog is as tall as its neighbours (contain:
           size keeps its cards from setting the row) and scrolls inside. */
        @media (min-width: 1200px) {
            #catalog-panel { display: flex; flex-direction: column; contain: size; min-height: 640px; }
            #catalog-panel .pick-grid { flex: 1 1 0; min-height: 0; max-height: none; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        /* Three abreast only once the panel fits cards near the frame's 197px
           (~187px from 1900); at 1600 three made 146px cards with a 34px picture. */
        @media (min-width: 1900px) { #catalog-panel .pick-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        /* 1200–1599: the photo column is ~200px, so its empty states are set
           smaller to fit the frame instead of being cut by it. */
        @media (min-width: 1200px) and (max-width: 1599px) {
            .dropzone { gap: 8px; padding: 16px 12px; }
            .dropzone .mark { width: 40px; height: 40px; margin-bottom: 4px; }
            .dropzone .mark svg { width: 20px; height: 20px; }
            .dropzone .dz-title { font-size: 14px; }
            .dropzone .dz-hint { font-size: 13px; }
            .dropzone .dz-btn { margin-top: 8px; padding: 12px 14px; font-size: 13px; letter-spacing: .08em; }
            .dropzone .dz-formats, .dropzone .generate-note { font-size: 12px; }
        }

        /* ---- Product detail modal (Desk_lk 1661:8117 → card 1661:9340) ----
           gallery | info | "Стилізуй з цим", on the layout's scrim. Sizes and
           colours from the frame are left out: the feeds carry neither. */
        .pd { position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; padding: 72px 24px 24px; }
        .pd-card { position: relative; width: 1011px; max-width: 100%; max-height: calc(100vh - 96px); overflow-y: auto;
            display: grid; grid-template-columns: 373px 366px 1px minmax(0, 1fr); column-gap: 16px;
            background: var(--surface); animation: npop .35s cubic-bezier(.22, 1, .36, 1);
            box-shadow: 0 30px 70px rgba(45, 45, 45, .35); }
        .pd-close { position: absolute; top: -60px; inset-inline-end: 0; width: 52px; height: 52px; padding: 0; border: none;
            background: none; cursor: pointer; color: var(--surface); }
        .pd-close:focus-visible { outline: 2px solid var(--surface); outline-offset: 2px; border-radius: 50%; }
        .pd-wrap { position: relative; width: 1011px; max-width: 100%; }

        .pd-gallery { display: flex; gap: 12px; height: 456px; padding-inline-start: 6px; }
        .pd-thumbs { flex: none; width: 60px; display: flex; flex-direction: column; gap: 11px; overflow-y: auto;
            scrollbar-width: thin; scrollbar-color: #2D2D2D rgba(45, 45, 45, .12); }
        .pd-thumb { flex: none; width: 60px; height: 93px; padding: 0; border: 1px solid transparent; border-radius: 0;
            overflow: hidden; cursor: pointer; background: var(--surface-2); }
        .pd-thumb.active { border-color: var(--ink-2); }
        .pd-thumb img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .pd-main { flex: 1; min-width: 0; border-radius: 0; overflow: hidden; background: var(--surface);
            display: flex; align-items: center; justify-content: center; }
        .pd-main img { width: 100%; height: 100%; object-fit: contain; display: block; }

        .pd-info { display: flex; flex-direction: column; gap: 13px; padding: 16px 0; min-width: 0; }
        .pd-brand { font-size: 14px; font-weight: 500; line-height: 1.5; color: var(--ink-2); text-transform: uppercase; }
        .pd-id { font-size: 14px; line-height: 1.5; color: var(--muted); }
        .pd-name { margin: 0; font-family: var(--serif); font-weight: 400; font-size: 16px; line-height: 1.4; color: var(--ink-2); }
        .pd-price { font-family: var(--serif); font-size: 22px; line-height: 1.3; color: var(--ink-2); }
        .pd-info hr { width: 100%; height: 1px; margin: 0; border: none; background: var(--line-soft); }
        .pd-label { font-size: 14px; line-height: 1.5; color: var(--muted); }
        .pd-desc { margin: 4px 0 0; font-size: 14px; line-height: 1.5; color: var(--ink-2); max-height: 150px; overflow-y: auto; }
        .pd-actions { margin-top: auto; display: flex; flex-direction: column; gap: 13px; }
        .pd-actions .dl-btn { padding-top: 13px; padding-bottom: 13px; }

        .pd-rule { background: var(--line-soft); }
        .pd-pairs { padding: 16px 16px 16px 0; min-width: 0; }
        .pd-pairs h3 { margin: 0; font-family: var(--serif); font-weight: 400; font-size: 16px; line-height: 1.4; color: var(--ink-2); }
        .pd-pairs .sub { margin: 12px 0 0; font-size: 14px; line-height: 1.5; color: var(--muted); }
        .pd-pair-grid { display: grid; grid-template-columns: repeat(2, 100px); gap: 12px 14px; margin-top: 22px; }
        .pd-pair { display: flex; flex-direction: column; gap: 6px; padding: 0; border: none; background: none; cursor: pointer;
            text-align: start; font-family: 'Montserrat', sans-serif; font-weight: 300; min-width: 0; }
        .pd-pair .pic { width: 100px; height: 100px; background: var(--surface); display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .pd-pair .pic img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .pd-pair:hover .pic { outline: 1px solid var(--ink-2); }
        .pd-pair:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
        .pd-pair .brand { font-size: 9px; line-height: 1.5; text-transform: uppercase; color: var(--muted);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pd-pair .name { font-family: var(--serif); font-size: 12px; color: var(--ink-2);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pd-pair .price { font-size: 12px; line-height: 1.5; color: var(--muted); }
        .pd-pairs .none { margin: 22px 0 0; font-size: 13px; line-height: 1.5; color: var(--muted); }

        @media (max-width: 1060px) {
            .pd { padding: 64px 12px 12px; align-items: flex-start; }
            .pd-card { grid-template-columns: minmax(0, 1fr); max-height: calc(100vh - 76px); }
            .pd-rule { display: none; }
            .pd-gallery { height: min(456px, 62vh); padding: 12px 12px 0; }
            .pd-info { padding: 16px; }
            .pd-pairs { padding: 0 16px 20px; border-top: 1px solid var(--line-soft); padding-top: 16px; }
            .pd-pair-grid { grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); }
            .pd-pair .pic { width: 100%; aspect-ratio: 1; height: auto; }
            .pd-close { top: -56px; width: 44px; height: 44px; }
            .pd-close svg { width: 44px; height: 44px; }
        }

        /* ---- The phone's product card (Figma 2093:9429) ----
           A 337px card on the blurred scrim: the photos swiped sideways with
           "1 з 4" and a bar per photo, back and like on the picture, then
           brand | ID, name, price and the button that tries the piece on. */
        .pm { position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; padding: 16px 19px; }
        .pm-scrim { position: absolute; inset: 0; background: rgba(45, 45, 45, .5); -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px); }
        .pm-card { position: relative; width: 337px; max-width: 100%; max-height: 100%; display: flex; flex-direction: column;
            background: var(--surface); border-radius: 20px; overflow: hidden; animation: npop .35s cubic-bezier(.22, 1, .36, 1); }
        /* White, not surface-2: the photos are on a white field, and the 3:4 frame
           leaves bars beside it in this 337x405 box that must not show as grey. */
        .pm-pics { position: relative; flex: 0 1 405px; min-height: 180px; background: var(--surface); border-radius: 20px 20px 0 0; overflow: hidden; }
        .pm-track { display: flex; height: 100%; overflow-x: auto; overflow-y: hidden; scroll-snap-type: x mandatory; scrollbar-width: none;
            -webkit-overflow-scrolling: touch; overscroll-behavior-x: contain; }
        .pm-track::-webkit-scrollbar { display: none; }
        /* A piece is always shown whole (AppearanceTest). */
        .pm-track img { flex: 0 0 100%; width: 100%; height: 100%; object-fit: contain; display: block; scroll-snap-align: start; }
        .pm-round { position: absolute; top: 17px; width: 32px; height: 32px; padding: 0; border: 0; border-radius: 50%; background: var(--surface);
            color: var(--ink-2); display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .pm-round:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
        .pm-back { inset-inline-start: 17px; }
        .pm-like { inset-inline-end: 17px; }
        .pm-like[aria-pressed="true"] svg { fill: currentColor; }
        /* Not uppercased: a capital "З" reads as a 3 between the digits. */
        .pm-count { position: absolute; inset-inline-start: 10px; bottom: 10px; padding: 10px 14px; background: rgba(240, 240, 240, .8);
            font-size: 14px; line-height: 1.5; color: var(--ink-2); pointer-events: none; }
        .pm-bars { display: flex; justify-content: center; gap: 4px; margin-top: 4px; }
        .pm-bars button { width: 17px; height: 4px; padding: 0; border: 0; border-radius: 2px; background: rgba(45, 45, 45, .3); cursor: pointer;
            transition: width .25s var(--ease), background-color .25s ease; }
        .pm-bars button.active { width: 91px; background: var(--ink-2); }
        .pm-info { padding: 20px 10px 0 11px; min-width: 0; }
        .pm-info.solo { padding-top: 28px; }
        .pm-row { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; font-size: 12px; line-height: 1.5; text-transform: uppercase; }
        .pm-brand { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--ink-2); }
        .pm-id { flex: none; color: var(--muted); }
        .pm-name { margin: 12px 0 0; font-family: var(--serif); font-weight: 400; font-size: 14px; line-height: 1.3; color: var(--ink-2);
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .pm-price { margin-top: 14px; font-family: var(--serif); font-size: 18px; line-height: 1.3; color: var(--ink-2); }
        .pm-go { flex: none; height: 45px; margin: 20px 10px 10px; border: 0; border-radius: 6px; background: var(--ink-2); color: var(--surface);
            font-family: inherit; font-weight: 300; font-size: 12px; letter-spacing: .13em; text-transform: uppercase; cursor: pointer; }
        .pm-go:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }

        /* ---- Share strip ---- */
        .share-strip { margin-top: 30px; padding: 30px 22px; border: 1px solid var(--line-soft); display: flex; flex-direction: column;
            gap: 24px; }
        /* Heading and the seven share buttons sit side by side only when both
           fit; otherwise the buttons drop under the heading, never squeezed. */
        @media (min-width: 834px) { .share-strip { flex-direction: row; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: 24px 40px; padding: 39px; } }
        .share-strip > * { min-width: 0; }
        .share-strip h2 { font-size: 28px; line-height: 1.1; }
        @media (min-width: 834px) { .share-strip h2 { font-size: 38px; line-height: 41.6px; } }
        .share-strip .lede { margin-top: 15px; }
        /* Icons as in the frame (1661:6608): 8px apart, 20px after the label. */
        .share-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .share-row .label { font-size: 16px; color: var(--muted); margin-inline-end: 12px; }
        /* The icon draws its own rounded frame, so the button is just its 52px
           hit area; hover lifts it like the page's other buttons. */
        .share-btn { width: 52px; height: 52px; padding: 0; border: none; background: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center; color: var(--ink-2);
            transition: transform .25s var(--ease), opacity .25s ease; }
        .share-btn:hover:not(:disabled) { transform: translateY(-2px); }
        .share-btn:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
        .share-btn:disabled { opacity: .35; cursor: default; }
        .share-note { width: 100%; font-size: 12.5px; color: var(--muted); }

        /* ---- Buy this look ---- */
        .dl-section { margin-top: 90px; }
        /* The frame's 3 x 378px only fits a 1920px screen; below that the
           columns shrink with the content area instead of pushing past it
           (next to the sidebar a 1280px screen leaves ~944px). Three columns
           wait for 1280px: narrower, the shop button no longer fits a card. */
        .shop-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; margin-top: 40px; }
        @media (min-width: 600px) { .shop-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1280px) { .shop-grid { grid-template-columns: repeat(3, minmax(0, 378px)); } }
        .shop-card { border: 1px solid var(--line-soft); background: var(--surface); padding: 12px; display: flex; flex-direction: column; }
        /* Product photos sit on white (dev-catalog/README.md), so every box that
           holds one is white too: a 3:4 photo in a wider box must not show grey bars. */
        .shop-card .pic { aspect-ratio: 353 / 388; background: var(--surface); display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .shop-card .pic img { width: 100%; height: 100%; object-fit: contain; }
        .shop-card .text { flex: 1; padding: 22px 10px 18px; text-align: center; }
        .shop-card .brand { font-size: 9.5px; letter-spacing: .04em; text-transform: uppercase; color: var(--muted);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .shop-card .name { font-family: var(--serif); font-size: 16px; line-height: 1.3; color: var(--ink-2); margin: 8px 0; }
        .shop-card .price { font-size: 12.7px; color: var(--muted); }
        .shop-card .dl-btn { min-height: 54px; font-size: 14px; }

        /* ---- Consultation block ---- */
        .consult { margin-top: 90px; display: grid; grid-template-columns: 1fr; border: 1px solid var(--line-soft); }
        @media (min-width: 834px) { .consult { grid-template-columns: 1fr 1fr; min-height: 442px; border: none; } }
        .consult .visual { position: relative; aspect-ratio: 16 / 9; overflow: hidden;
            background: repeating-linear-gradient(45deg, #F0F0F0 0 14px, rgba(45, 45, 45, .05) 14px 28px); }
        @media (min-width: 834px) { .consult .visual { aspect-ratio: auto; } }
        .consult .visual img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .consult .visual .caption { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
            font-size: 9px; letter-spacing: .2em; text-transform: uppercase; color: var(--muted); white-space: nowrap; }
        .consult .text { background: var(--ink-2); padding: 40px 24px; display: flex; flex-direction: column; gap: 32px; }
        @media (min-width: 834px) { .consult .text { padding: 60px 44px; gap: 45px; } }
        .consult h2 { font-size: 26px; line-height: 1.2; text-transform: uppercase; color: rgba(255, 255, 255, .87); max-width: 18ch; }
        @media (min-width: 834px) { .consult h2 { font-size: 35px; } }
        .consult .copy { display: flex; flex-direction: column; gap: 15px; max-width: 513px; }
        .consult .copy p { margin: 0; font-size: 16px; line-height: 1.5; color: rgba(255, 255, 255, .62); }

        /* ---- Lead cards ---- */
        .cta-grid { display: grid; grid-template-columns: 1fr; gap: 24px; margin-top: 90px; }
        /* minmax(0, …): equal cards — plain 1fr let a long heading or the
           form's row widen one card and push another's button past its edge. */
        @media (min-width: 1280px) { .cta-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .cta-card { border: 1px solid var(--line-soft); background: var(--surface); padding: 44px 12px 12px; display: flex; flex-direction: column; }
        /* The text takes the slack so every card's form (and the middle card's
           button) sits on one line at the foot, whatever the copy's length. */
        .cta-card .text { flex: 1 0 auto; text-align: center; padding: 0 10px; margin-bottom: 40px; }
        .cta-card > .dl-btn { margin-top: auto; }
        .cta-card h3 { font-size: 22px; line-height: 1.4; text-transform: uppercase; color: var(--ink-2); }
        @media (min-width: 834px) { .cta-card h3 { font-size: 24px; line-height: 41.6px; } }
        .cta-card .text p { margin: 12px 0 0; font-size: 14px; line-height: 1.5; color: var(--muted); }
        /* Field and button share one row on every desktop width, as in the
           frame (302 + 7 + 178 of a 487px card); only the phone layout below
           834px stacks them. Three cards at 1280–1919px are only ~270–450px
           wide, so there the button is set tighter to leave the field room. */
        .cta-form { margin-top: auto; display: flex; gap: 7px; }
        .cta-form .dl-btn { flex: none; }
        @media (min-width: 1280px) and (max-width: 1919px) {
            .cta-card .cta-form .dl-btn { padding-inline-start: 14px; padding-inline-end: 14px; font-size: 13px; letter-spacing: .08em; }
            /* The middle card's button matches them: one line, the forms' height. */
            .cta-card > .dl-btn { padding-inline-start: 14px; padding-inline-end: 14px; font-size: 13px; letter-spacing: .06em; white-space: nowrap; }
            .cta-card .cta-form input { padding-inline-start: 14px; padding-inline-end: 14px; font-size: 13px; letter-spacing: .06em; }
        }
        .cta-form input { flex: 1; min-width: 0; padding: 16px 23px; border: 1px solid var(--ink-2); border-radius: 0; background: none;
            font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 14px; letter-spacing: .13em; text-transform: uppercase;
            color: var(--ink-2); outline: none; }
        @media (min-width: 834px) { .cta-form input { font-size: 16px; } }
        .cta-form input:focus { box-shadow: 0 0 0 3px rgba(45, 45, 45, .12); }
        .cta-form input::placeholder { text-transform: uppercase; color: rgba(45, 45, 45, .5); }
        .cta-card .note { margin: 18px 0 0 1px; font-size: 14px; line-height: 1.5; color: var(--muted); min-height: 1.5em; }
        /* Side by side, the notes run one or two lines: reserve two so the
           forms above them stay level. */
        @media (min-width: 1280px) { .cta-card .note { min-height: 3em; } }

        /* ---- Plans ---- */
        /* All five plans a row only on the 1920px frame: a card cannot get
           narrower than "SHOPAHOLIC" plus its padding (~265px), and next to
           the sidebar a 1280px screen leaves ~944px, a 1440px one ~1104px. */
        .plans-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; margin-top: 40px; }
        @media (min-width: 600px) { .plans-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1280px) { .plans-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (min-width: 1920px) { .plans-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
        .plan-card { display: flex; flex-direction: column; justify-content: space-between; min-height: 449px; padding: 32px;
            border: 1px solid rgba(45, 45, 45, .06); --plan-ink: #2D2D2D; --plan-muted: #6B6B6B; --plan-line: rgba(45, 45, 45, .12);
            color: var(--plan-ink); }
        /* The frame's neutral greys (its two light card fills), mixed from ink
           over the white page rather than added as new hues. */
        .plan-card.tone-light { background: rgba(45, 45, 45, .03); }
        .plan-card.tone-cream { background: rgba(45, 45, 45, .07); }
        .plan-card.tone-dark { background: var(--ink-2); border-color: rgba(255, 255, 255, .12);
            --plan-ink: rgba(255, 255, 255, .87); --plan-muted: rgba(255, 255, 255, .62); --plan-line: rgba(255, 255, 255, .14); }
        .plan-card .top { display: flex; flex-direction: column; gap: 24px; }
        .plan-card .meta { display: flex; justify-content: space-between; font-size: 12px; font-weight: 500; color: var(--plan-muted); }
        .plan-card .meta span:last-child { text-transform: uppercase; opacity: .8; }
        .plan-card hr { width: 100%; height: 1px; margin: 0; border: none; background: var(--plan-line); }
        .plan-card .name { font-family: var(--serif); font-size: 28px; line-height: 1.2; text-transform: uppercase; }
        .plan-card .price { font-family: var(--serif); font-size: 24px; line-height: 1.2; margin-top: 8px; }
        .plan-card .tagline { margin: 0; font-size: 14px; line-height: 1.5; }
        .plan-card .tryons { padding-top: 8px; display: flex; flex-direction: column; gap: 12px; }
        .plan-card .tryons span { font-size: 11px; font-weight: 500; letter-spacing: .02em; text-transform: uppercase; color: var(--plan-muted); opacity: .7; }
        .plan-card .tryons p { margin: 0; font-size: 14px; line-height: 1.4; color: var(--plan-muted); }
        .plan-card .bottom { padding-top: 32px; }
        .plan-card .go { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding-top: 20px;
            font-size: 14px; font-weight: 500; color: var(--plan-ink); text-decoration: none; }
        .plan-card .go svg { flex: none; transition: transform .3s var(--ease); }
        .plan-card .go:hover svg { transform: translateX(4px); }
        .plans-note { margin: 24px 0 0; font-size: 12.5px; line-height: 1.6; color: var(--muted); }

        /* Plan tabs (mobile switcher) */
        .plan-tabs { display: flex; flex-wrap: wrap; justify-content: center; gap: 5px; margin-top: 30px; }
        .plan-tab { width: 107px; padding: 8px 10px; border: none; background: rgba(45, 45, 45, .07); cursor: pointer;
            font-family: var(--serif); font-size: 14px; line-height: 1.2; color: rgba(45, 45, 45, .4); text-align: center; }
        .plan-tab span { display: block; }
        .plan-tab .t-price { margin-top: 4px; }
        .plan-tab.active { background: var(--ink-2); color: rgba(255, 255, 255, .87); }

        /* ---- LK_mob (1431:6604); .only-mobile / .only-desktop live in layout.blade.php ---- */
        @media (max-width: 833px) {
            .dl-head > div { width: 100%; text-align: center; }
            .dl .lede { margin-inline-start: auto; margin-inline-end: auto; }
            .dl-actions { width: 100%; display: grid; grid-template-columns: 1fr 1fr; gap: 9px; }
            .dl-btn { min-height: 45px; padding: 12px 10px; font-size: 13px; }

            /* ---- Phone: steps 1–3 are the try-on screen (#m-tryon, below);
               the panels here are the result, which only appears once there
               is something in it (Figma 1872:8785). Its greys are ink at low
               alpha: F4F4F4 ≈ 5%, F6F6F6 ≈ 4%, DDD = --line-soft. ---- */
            .tryon-grid { gap: 0; margin-top: 0; }
            #result-panel { order: 3; margin-top: 30px; }
            #result-panel.m-idle { display: none; }
            .panel { border: none; padding: 0; }
            .panel-label { font-size: 14px; margin-bottom: 8px; }
            .dropzone { padding: 24px 40px; }
            #result-panel .frame-2x3 { aspect-ratio: auto; min-height: 494px; }

            /* Step pills: a numbered dot and the step's name */
            .m-step { display: inline-flex; align-items: center; gap: 4px; margin: 0; padding: 2px 10px 2px 2px;
                border-radius: 20px; background: rgba(45, 45, 45, .05); font-size: 12px; line-height: 16px;
                color: var(--ink-2); white-space: nowrap; }
            .m-step.is-light { align-self: flex-start; background: var(--surface); color: var(--muted); }
            .m-dot { position: relative; flex: none; width: 16px; height: 16px; }
            .m-dot img { display: block; }
            .m-dot span { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
                font-size: 9px; line-height: 1; color: var(--surface); }

            .result-actions { margin-top: 20px; }
            .result-actions .dl-btn { min-height: 45px; }

            /* ---- Phone result (Figma 1872:8785): the steps give way to the look ---- */
            /* The desktop's photo and catalog panels: the phone has the screen. */
            #photo-panel, #catalog-panel { display: none; }
            .tryon-grid.has-result #result-panel { margin-top: 13px; }
            .m-step-lg { display: flex; width: max-content; margin: 0 auto 8px; padding: 4px 10px 4px 4px; font-size: 12px; }
            .m-dot-lg { width: 18px; height: 18px; }
            .m-dot-lg span { font-size: 12px; }
            /* Portrait 3:4 before/after: phone photos are vertical, so the
               figure fills the frame; any other shape still fits whole on the
               frame's grey instead of being cut. */
            .tryon-grid.has-result #result-panel .frame-2x3 { aspect-ratio: 3 / 4; min-height: 0; border-radius: 8px;
                background: rgba(45, 45, 45, .18); }
            /* Scrolling on holds the frame under the top bar while it shrinks
               to half, and what follows rises right under it — never beneath
               it; then the frame scrolls away with the rest. shrinkFrame()
               sets the shift, the scale and the box they take. The box grows
               with the scroll, so the browser must not anchor against it. */
            html:has(.tryon-grid.has-result) { overflow-anchor: none; }
            .tryon-grid.has-result .m-shrink { height: var(--shrink-h, auto); }
            .tryon-grid.has-result .m-shrink .frame-2x3 { transform-origin: 50% 0; will-change: transform;
                transform: translateY(var(--shrink-y, 0px)) scale(var(--shrink-s, 1)); }
            #result-panel .compare img { object-fit: contain; }
            #result-panel .compare .knob { width: 32px; height: 32px; margin: -16px 0 0 -16px; }
            #result-panel .compare .knob svg { width: 12.8px; height: 12.8px; }
            .m-cmp-label { position: absolute; top: 14px; font-size: 14px; font-weight: 400; line-height: 1.5;
                text-transform: uppercase; color: var(--surface); pointer-events: none; }
            .m-cmp-label.is-before { left: 14px; }
            .m-cmp-label.is-after { right: 14px; }
            .m-cmp-caption { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center;
                justify-content: flex-end; gap: 3px; padding-bottom: 10px; clip-path: inset(0 0 0 var(--split));
                color: var(--surface); text-align: center; text-transform: uppercase; pointer-events: none; }
            .m-cmp-caption .cap-kicker { font-size: 9.9px; font-weight: 400; line-height: 1.5; letter-spacing: 2.17px; }
            .m-cmp-caption .cap-name { font-family: var(--serif); font-size: 16.7px; line-height: 1.2; }
            /* Four actions, 8px apart */
            .m-tiles { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin-top: 8px; }
            .m-tile { display: flex; flex-direction: column; align-items: center; justify-content: flex-start; gap: 3px;
                height: 51px; padding: 6px 2px 0; border: none; border-radius: 8px; background: rgba(45, 45, 45, .05);
                font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 12px; line-height: 1.5; color: var(--ink-2);
                text-decoration: none; cursor: pointer; }
            .m-tile img { display: block; height: 18px; width: auto; }
            .m-tile[aria-disabled="true"] { cursor: default; }
            .m-tile:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
            .m-copied { margin: 6px 0 0; font-size: 10px; text-align: center; color: var(--muted); }
            /* This photo's looks to compare (335×52, Figma 1873:7711) */
            .m-cmp-tray { display: flex; align-items: center; gap: 16px; margin-top: 8px; min-height: 52px; padding: 4px 10px 4px 8px;
                border-radius: 8px; background: rgba(45, 45, 45, .05); }
            .m-cmp-count { flex: none; margin: 0; font-size: 12px; font-weight: 300; line-height: 1.5; color: var(--ink-2); }
            .m-cmp-count b { font-weight: 500; }
            .m-cmp-thumbs { display: flex; gap: 6px; }
            .m-cmp-thumb { position: relative; width: 44px; height: 44px; }
            .m-cmp-pick { display: block; width: 100%; height: 100%; padding: 0; border: none; border-radius: 4px;
                overflow: hidden; background: var(--surface); cursor: pointer; }
            .m-cmp-pick img { display: block; width: 100%; height: 100%; object-fit: contain; }
            .m-cmp-thumb.is-picked .m-cmp-pick { outline: 1.5px solid var(--ink-2); outline-offset: 1px; }
            .m-cmp-remove { position: absolute; top: -3px; inset-inline-end: -4px; width: 14px; height: 14px; padding: 0; border: none;
                background: none; cursor: pointer; }
            .m-cmp-remove svg { display: block; }
            .m-cmp-pick:focus-visible, .m-cmp-remove:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
            .m-cmp-actions { display: flex; gap: 6px; margin-inline-start: auto; }
            .m-cmp-actions .m-btn { min-width: 73px; padding: 0 6px; }
            .m-cmp-add { flex-direction: column; gap: 2px; }
            .m-btn:disabled { opacity: .4; cursor: default; }
            /* The piece (335×116) */
            .m-item { position: relative; display: grid; grid-template-columns: 91px minmax(0, 1fr); column-gap: 8px;
                margin-top: 8px; padding: 4px; border-radius: 8px; background: rgba(45, 45, 45, .05); min-height: 116px; }
            .m-item-pic { grid-row: 1 / 3; height: 108px; border-radius: 4px; overflow: hidden; background: var(--surface); }
            .m-item-pic img { display: block; width: 100%; height: 100%; object-fit: contain; }
            .m-item-text { min-width: 0; padding: 6px 30px 0 0; display: flex; flex-direction: column; gap: 8px; }
            .m-item-text p { margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .m-item-brand { font-size: 9px; line-height: 1.5; text-transform: uppercase; color: var(--muted); }
            .m-item-name { font-family: var(--serif); font-size: 12px; line-height: normal; color: var(--ink-2); }
            .m-item-price { font-size: 14px; line-height: 1; color: var(--muted); }
            .m-item-like { position: absolute; top: 8px; inset-inline-end: 8px; width: 18px; height: 18px; padding: 0; border: none;
                background: none; cursor: pointer; }
            .m-item-like img { display: block; }
            .m-item-like:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
            .m-item-actions { align-self: end; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 0 4px 0 0; }
            .m-btn { display: inline-flex; align-items: center; justify-content: center; height: 32px; padding: 0 8px;
                border-radius: 4px; font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 9px; letter-spacing: 1.17px;
                text-transform: uppercase; white-space: nowrap; text-decoration: none; cursor: pointer; }
            .m-btn-outline { border: 1px solid rgba(45, 45, 45, .6); background: none; color: var(--ink-2); }
            .m-btn-dark { border: 1px solid var(--ink-2); background: var(--ink-2); color: var(--surface); }
            .m-btn:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
            /* The stylist (335×63) */
            .m-ask { display: flex; align-items: center; gap: 16px; margin-top: 8px; min-height: 63px; padding: 5px 10px 0 24px;
                border-radius: 8px; background: rgba(45, 45, 45, .05); overflow: hidden; }
            .m-ask-pic { flex: none; align-self: flex-end; width: 50px; height: 58px; object-fit: cover; object-position: 50% 0; transform: scaleX(-1); }
            .m-ask-text { flex: 1; min-width: 0; padding-bottom: 5px; }
            .m-ask-text p { margin: 0; }
            .m-ask-title { font-family: var(--serif); font-size: 12px; line-height: normal; color: var(--ink-2);
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .m-ask-sub { margin-top: 4px !important; font-size: 9px; line-height: 1.5; color: var(--muted); }
            .m-ask .m-btn { flex: none; align-self: center; margin-bottom: 5px; padding: 0 10px; }
        }

        /* ---- Phone try-on screen (the design hand-over "Примірка", in the site's
           monochrome): one viewport, no page scroll, from 390×844 down to
           375×667. While no look is on screen (html.m-fit) the shell is a
           column — top bar, the screen, the tabs — and the card row is the
           only block that gives; a finished look returns the page to its
           usual scroll for the result screen. ---- */
        @media (max-width: 833px) {
            html.m-fit .acct-shell { height: 100vh; height: 100dvh; min-height: 0; flex-direction: column; flex-wrap: nowrap; overflow: hidden; }
            html.m-fit .acct-topbar, html.m-fit .acct-tabs { flex: 0 0 auto; }
            /* The screen's colours (the design hand-over): --ink, --surface,
               --surface-2 and --muted are the site's own; these two greys draw
               its lines — card and field outlines, the line over the tabs, the
               upload card's dash. */
            html.m-fit { --border: #E6E6E6; --dashed: #BDBDBD; }
            html.m-fit .acct-tabs { position: static; border-top-color: var(--border); }
            html.m-fit .acct-content, html.m-fit #dashboard-section, html.m-fit #tryon-section { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
            html.m-fit .acct-content { padding: 0; }
            html.m-fit #depleted-row, html.m-fit .tryon-grid { display: none; }

            .mt { --mt-display: 'Playfair Display', Georgia, serif; --mt-body: 'Manrope', system-ui, sans-serif;
                flex: 1 1 auto; min-height: 0; width: 100%; max-width: 480px; margin: 0 auto;
                display: flex; flex-direction: column; font-family: var(--mt-body); font-weight: 400; color: var(--ink); }
            .mt > * { flex: 0 0 auto; }
            .mt button { font-family: inherit; }

            /* Hero | step 1, 240px */
            .mt-top { display: flex; gap: 8px; padding: 0 12px 0 0; height: 240px; margin-top: 8px; position: relative; }
            .mt-hero { width: 214px; flex-shrink: 0; position: relative; overflow: hidden; }
            /* A transparent PNG, the model already facing the text: no backing. */
            .mt-hero-img { position: absolute; inset-inline-start: 8px; top: 0; width: 76px; height: 90%; object-fit: cover; object-position: center top; display: block; }
            .mt-intro { position: absolute; inset-inline-start: 80px; top: 8px; width: 134px; display: flex; flex-direction: column; gap: 8px; }
            .mt-title { margin: 0; font-family: var(--mt-display); font-weight: 500; font-size: 16px; line-height: 1.0; letter-spacing: -0.02em; white-space: nowrap; color: var(--ink); }
            .mt-lede { margin: 0; font-size: 10.5px; line-height: 1.35; white-space: nowrap; color: var(--muted); }
            .mt-tips { list-style: none; margin: 6px 0 0; padding: 0; display: flex; flex-direction: column; gap: 9px; }
            .mt-tips li { display: flex; align-items: center; gap: 7px; font-size: 10px; line-height: 1.2; font-weight: 500; white-space: nowrap; }
            .mt-tip-icon { width: 28px; height: 28px; border-radius: 14px; background: var(--surface-2); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

            .mt-step1 { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; padding-top: 2px; }
            /* Stretched 12px past the hero, so the upload card ends level with
               the raised step 2 pill beside it (240 - 30 + 10 + 32 = 252). */
            .mt-top .mt-step1 { margin-bottom: -12px; }
            .mt-pill1 { display: flex; align-items: center; gap: 7px; margin: 0; background: var(--surface-2); border-radius: 999px; padding: 4px 10px 4px 4px; align-self: stretch; }
            .mt-num { border-radius: 50%; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
            .mt-num-1 { width: 20px; height: 20px; background: var(--ink); color: var(--surface); font-size: 10px; }
            .mt-pill-text { font-family: var(--mt-display); font-weight: 500; font-size: 12.5px; white-space: nowrap; }
            .mt-drop { flex: 1; min-height: 0; background: var(--surface); border: 1.5px dashed var(--dashed); border-radius: 20px; padding: 12px 10px 10px 10px;
                display: flex; flex-direction: column; align-items: center; gap: 5px; text-align: center; position: relative; }
            .mt-drop.over { border-color: var(--ink); }
            .mt-drop-icon { width: 48px; height: 48px; border-radius: 24px; background: var(--surface-2); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
            .mt-drop-title { margin: 2px 0 0; display: flex; align-items: center; gap: 5px; font-family: var(--mt-display); font-weight: 500; font-size: 17px; line-height: 1.1; }
            .mt-drop-title img { display: block; flex: none; }
            .mt-drop-text { margin: 0; font-size: 10px; line-height: 1.4; color: var(--muted); }
            .mt-upload { margin-top: auto; width: 100%; height: 40px; border: 0; border-radius: 20px; background: var(--ink); color: var(--surface); font-weight: 600; font-size: 12.5px;
                display: flex; align-items: center; justify-content: center; gap: 6px; cursor: pointer; }
            .mt-upload:focus-visible { outline: 2px solid var(--ink); outline-offset: 2px; }
            .mt-formats { margin: 0; font-size: 9.5px; color: var(--muted); }

            /* Step 1 once the photo is in: one strip */
            .mt-strip { display: flex; align-items: center; gap: 10px; margin: 8px 12px 0 12px; padding: 6px 8px 6px 6px; border: 1px solid var(--border); border-radius: 16px; }
            .mt-strip-photo { width: 44px; height: 58px; border-radius: 10px; object-fit: cover; object-position: center top; display: block; flex-shrink: 0; background: var(--surface-2); }
            .mt-strip-text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
            .mt-strip-step { margin: 0; display: flex; align-items: center; gap: 6px; font-size: 10px; color: var(--muted); }
            .mt-strip-title { margin: 0; display: flex; align-items: center; gap: 5px; font-family: var(--mt-display); font-weight: 500; font-size: 15px; line-height: 1.1; }
            .mt-strip-title img { display: block; flex: none; }
            .mt-change { flex-shrink: 0; height: 32px; padding: 0 14px; border: 0; border-radius: 16px; background: var(--surface-2); color: var(--ink); font-size: 11px; font-weight: 600; cursor: pointer; }
            .mt-change:focus-visible { outline: 2px solid var(--ink); outline-offset: 2px; }

            /* Step 2 */
            .mt-step2 { display: flex; align-items: center; padding: 10px 12px 0 12px; }
            /* The model takes 90% of the 240px hero: the pill rises into the
               space left under her feet, beside the upload card. */
            .mt-step2.is-raised { margin-top: -30px; position: relative; align-self: flex-start; pointer-events: none; }
            .mt-pill2 { display: inline-flex; align-items: center; gap: 8px; background: var(--surface-2); border-radius: 999px; padding: 5px 14px 5px 5px; margin: 0; }
            .mt-num-2 { width: 22px; height: 22px; background: var(--ink); color: var(--surface); font-size: 11px; }
            .mt-pill2 .mt-pill-text { font-size: 14px; }

            /* Search + brand */
            .mt-search-row { display: flex; gap: 8px; padding: 8px 12px 0 12px; position: relative; }
            .mt-search { flex: 1; min-width: 0; display: flex; align-items: center; gap: 8px; height: 40px; border-radius: 20px; background: var(--surface);
                border: 1px solid var(--border); padding: 0 12px; color: var(--muted); }
            .mt-search input { flex: 1; min-width: 0; border: 0; background: transparent; font-family: inherit; font-size: 11px; color: var(--ink); outline: none; }
            .mt-search input::placeholder { color: var(--muted); }
            .mt-search input::-webkit-search-cancel-button { display: none; }
            .mt-brands { height: 40px; border-radius: 20px; background: var(--surface); border: 1px solid var(--border); padding: 0 10px 0 12px;
                display: flex; align-items: center; gap: 8px; font-size: 11px; font-weight: 500; color: var(--ink); cursor: pointer; flex-shrink: 0; max-width: 140px; }
            .mt-brands span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .mt-brand-list { position: absolute; inset-inline-end: 12px; top: 52px; z-index: 5; min-width: 160px; max-height: 260px; overflow-y: auto; margin: 0; padding: 6px;
                list-style: none; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; box-shadow: 0 12px 28px -16px rgba(45, 45, 45, .45); }
            .mt-brand-list button { width: 100%; padding: 8px 10px; border: 0; border-radius: 10px; background: transparent; text-align: start; font-size: 11px; color: var(--ink); cursor: pointer; }
            .mt-brand-list button:hover, .mt-brand-list button[aria-pressed="true"] { background: var(--surface-2); }

            /* Chips: one row, scrolled sideways */
            .mt-chips { display: flex; gap: 5px; padding: 8px 12px 0 12px; overflow-x: auto; overflow-y: hidden; scrollbar-width: none;
                -webkit-overflow-scrolling: touch; overscroll-behavior-x: contain; }
            .mt-chips::after { content: ''; flex: none; width: 7px; }
            .mt-chips::-webkit-scrollbar { display: none; }
            .mt-chip { flex: none; height: 30px; border-radius: 15px; border: 0; background: var(--surface-2); color: var(--ink); font-size: 10.5px; font-weight: 500;
                padding: 0 10px; white-space: nowrap; cursor: pointer; }
            .mt-chip[aria-pressed="true"] { background: var(--ink); color: var(--surface); font-weight: 600; }

            /* Cards: two and a bit to the width (the price and "Приміряти" share
               a row), the rest scrolled sideways; as tall as the viewport leaves. */
            .mt > .mt-grid { flex: 1 1 auto; min-height: 0; }
            .mt-grid { display: grid; grid-auto-flow: column; grid-auto-columns: calc((100% - 14px) * 5 / 12); grid-template-rows: minmax(0, 1fr); gap: 7px; margin: 8px 12px 0 12px;
                overflow-x: auto; overflow-y: hidden; scroll-snap-type: x mandatory; scrollbar-width: none; }
            .mt-grid::-webkit-scrollbar { display: none; }
            .mt-card { background: var(--surface); border-radius: 14px; padding: 6px 6px 7px 6px; display: flex; flex-direction: column; gap: 3px;
                border: 1px solid var(--border); scroll-snap-align: start; min-width: 0; min-height: 0; }
            .mt-card.is-picked { border-color: var(--ink); }
            .mt-card-pic { position: relative; flex: 1; min-height: 0; border-radius: 10px; overflow: hidden; background: var(--surface); }
            .mt-card-open { display: block; width: 100%; height: 100%; padding: 0; border: 0; background: none; cursor: pointer; }
            /* A piece is always shown whole (AppearanceTest). */
            .mt-card-pic img { width: 100%; height: 100%; object-fit: contain; display: block; }
            .mt-like { position: absolute; top: 5px; inset-inline-end: 5px; width: 24px; height: 24px; border-radius: 12px; border: 0; background: var(--surface); color: var(--ink);
                display: flex; align-items: center; justify-content: center; padding: 0; cursor: pointer; }
            .mt-like[aria-pressed="true"] svg { fill: currentColor; }
            .mt-brand { font-size: 8.5px; letter-spacing: 0.06em; color: var(--muted); margin-top: 3px; text-transform: uppercase;
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .mt-name { font-size: 10px; line-height: 1.25; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .mt-buy { display: flex; align-items: center; justify-content: space-between; gap: 4px; margin-top: 3px; }
            .mt-price { font-family: var(--mt-display); font-weight: 600; font-size: 12px; white-space: nowrap; }
            .mt-try { flex: 0 1 auto; min-width: 0; padding: 0 10px; white-space: nowrap; height: 28px; border-radius: 14px; border: 0; background: var(--surface-2); color: var(--ink);
                font-size: 10px; font-weight: 600; cursor: pointer; }
            .mt-try[aria-pressed="true"] { background: var(--ink); color: var(--surface); }
            .mt-empty { margin: 8px 12px 0; font-size: 10.5px; color: var(--muted); }

            /* Step 3 */
            .mt-status { margin: 0; padding: 8px 12px 0 12px; font-size: 10.5px; font-weight: 500; text-align: center; color: var(--danger); }
            .mt-consent { display: flex; flex-direction: column; gap: 4px; padding: 6px 12px 0; font-size: 9.5px; color: var(--ink); }
            .mt-consent label { display: flex; gap: 6px; align-items: flex-start; }
            .mt-go-wrap { padding: 8px 12px 8px 12px; }
            .mt-go { width: 100%; height: 44px; border-radius: 22px; border: 0; background: var(--ink); color: var(--surface); font-family: var(--mt-display); font-weight: 500;
                font-size: 17px; display: flex; align-items: center; justify-content: center; gap: 10px; cursor: pointer; position: relative; }
            .mt-go:disabled { opacity: .45; cursor: not-allowed; }
            /* Step 3 is the button itself: its number sits inside, on the left —
               a dark circle with a white digit like the other steps, ringed in
               white so it reads on the dark button. */
            .mt-num-3 { position: absolute; inset-inline-start: 8px; top: 8px; width: 28px; height: 28px; background: var(--ink); color: var(--surface);
                box-shadow: inset 0 0 0 1px var(--surface); font-family: var(--mt-body); font-size: 12px; }
        }
        /* Short phones (375×667): no tips, a smaller photo, a lower top block. */
        @media (max-width: 833px) and (max-height: 700px) {
            .mt-top { height: 180px; }
            .mt-tips { display: none; }
            .mt-hero { width: 190px; }
            .mt-hero-img { inset-inline-start: 0; width: 63px; }
            .mt-intro { inset-inline-start: 59px; width: 131px; }
            .mt-lede { font-size: 10px; }
            .mt-step2.is-raised { margin-top: -22px; }
            .mt-top .mt-step1 { margin-bottom: -20px; }
            .mt-drop-icon { display: none; }
        }

        /* The page ends at the try-on, on every screen: no share strip, "buy
           this look", stylist, CTA cards or plans — the owner's call (phone
           2026-10-13, desktop and tablet 2026-10-14). The markup stays so the
           sections can come back by dropping this rule. */
        #share-section, #shop-section, #consult-section, #cta-cards, #plans-section { display: none; }
@endsection

{{-- Fitted from the first paint: Alpine (from the CDN) sets the class only
     later, and until then the catalog cards grew to the pictures' size. --}}
@section('html-class', 'm-fit')

@section('section')
    <div class="section-rise dl" id="dashboard-section" x-data="dashboard()"
         x-effect="document.documentElement.classList.toggle('m-fit', ! result)">

        {{-- ============ Try-on workspace (frame 1417:6394) ============ --}}
        <section id="tryon-section">
            {{-- Page head (Figma 1893:9510). The frame's two buttons beside it
                 (catalog / own garment) wait for the own-garment upload. --}}
            @php($firstName = \Illuminate\Support\Str::before(trim((string) request()->user()->name), ' '))
            <div class="dl-head tryon-head only-desktop" id="tryon-head">
                <div>
                    <div class="eyebrow">{{ $firstName !== '' ? __('dashboard.head_eyebrow_named', ['name' => $firstName]) : __('dashboard.head_eyebrow') }}</div>
                    <h1>{{ __('dashboard.head_title') }}</h1>
                    <p class="lede">{{ __('dashboard.head_lede') }}</p>
                </div>
            </div>

            @if ($depleted)
                <button class="depleted-row" id="depleted-row" x-on:click="openModal('paywall')">
                    <span class="msg">{{ __('dashboard.depleted_msg') }}</span>
                    <span class="cta">{{ __('dashboard.depleted_cta') }}</span>
                </button>
            @endif

            {{-- Phone: steps 1–3 --}}
            @include('membership.partials.m-tryon')

            <div class="tryon-grid" :class="{ 'has-look': result && chosen(), 'has-result': result }">
                {{-- Your photo --}}
                <div class="panel" id="photo-panel">
                    <p class="panel-label only-desktop">{{ __('dashboard.your_photo') }}</p>
                    {{-- Empty state is the LK_mob dropzone (the desktop frame only
                         shows a filled photo); it takes a drop or a click. --}}
                    <div class="frame-2x3 only-desktop" :class="{ 'has-photo': photoUrl, 'is-over': dragOver }"
                         x-on:dragover.prevent="dragOver = true" x-on:dragleave.prevent="dragOver = false"
                         x-on:drop.prevent="dropPhoto($event)">
                        <template x-if="photoUrl"><img :src="photoUrl" alt="{{ __('dashboard.your_photo') }}"></template>
                        <div class="dropzone" x-show="! photoUrl" id="photo-dropzone">
                            <span class="mark"><svg width="18" height="18" viewBox="0 0 22 22" fill="none" aria-hidden="true"><path d="M8 3.5c0 1.5 1.3 2.5 3 2.5s3-1 3-2.5M8.5 5.5L3 9l2 3 2.5-1v8h7v-8l2.5 1 2-3-5.5-3.5" stroke="#2D2D2D" stroke-width="1.3" stroke-linejoin="round"/></svg></span>
                            <p class="dz-title">{{ __('dashboard.upload_title') }}</p>
                            <p class="dz-hint">{{ __('dashboard.upload_hint') }}</p>
                            <button type="button" class="dl-btn dl-btn-dark dz-btn" x-on:click="$refs.photo.click()">{{ __('dashboard.add_photo') }}</button>
                            <p class="dz-formats">{{ __('dashboard.formats') }}</p>
                        </div>
                    </div>
                    <input type="file" accept="image/jpeg,image/png,image/webp" hidden x-ref="photo" x-on:change="pickPhoto($event)">
                    {{-- Once a photo is in, the dropzone is gone; this is the way to another one. --}}
                    <button type="button" class="dl-btn dl-btn-outline dl-btn-block replace-btn only-desktop" id="replace-photo"
                            x-show="photoUrl" x-cloak x-on:click="$refs.photo.value = ''; $refs.photo.click()">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><g transform="translate(3.5 4.5)"><path d="M0.5 13.5V3.5C0.5 3.235 0.605 2.98 0.793 2.793C0.98 2.605 1.235 2.5 1.5 2.5H3L4.707 0.793C4.894 0.605 5.149 0.5 5.414 0.5H11.586C11.851 0.5 12.106 0.605 12.293 0.793L14 2.5H15.5C15.765 2.5 16.02 2.605 16.207 2.793C16.395 2.98 16.5 3.235 16.5 3.5V13.5C16.5 13.765 16.395 14.02 16.207 14.207C16.02 14.395 15.765 14.5 15.5 14.5H1.5C1.235 14.5 0.98 14.395 0.793 14.207C0.605 14.02 0.5 13.765 0.5 13.5Z" stroke="currentColor" stroke-linejoin="round"/><path d="M11.5 7.5C11.5 8.296 11.184 9.059 10.621 9.621C10.059 10.184 9.296 10.5 8.5 10.5C7.704 10.5 6.941 10.184 6.379 9.621C5.816 9.059 5.5 8.296 5.5 7.5C5.5 6.704 5.816 5.941 6.379 5.379C6.941 4.816 7.704 4.5 8.5 4.5C9.296 4.5 10.059 4.816 10.621 5.379C11.184 5.941 11.5 6.704 11.5 7.5Z" stroke="currentColor" stroke-linejoin="round"/></g></svg>
                        {{ __('dashboard.replace_photo') }}
                    </button>
                    {{-- What makes a photo work for try-on (Main 1661:6534). --}}
                    <ul class="photo-tips only-desktop" id="photo-tips">
                        @foreach (__('dashboard.tips') as $tip)
                            <li>
                                <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M9 16.219C5.013 16.219 1.781 12.987 1.781 9C1.781 5.013 5.013 1.781 9 1.781C12.987 1.781 16.219 5.013 16.219 9C16.219 12.987 12.987 16.219 9 16.219ZM12.245 6.573C12.197 6.525 12.14 6.487 12.078 6.461C12.015 6.435 11.948 6.422 11.88 6.422C11.812 6.422 11.745 6.435 11.683 6.461C11.62 6.487 11.563 6.525 11.515 6.573L8.227 9.862C8.13 9.959 7.999 10.013 7.862 10.013C7.726 10.013 7.594 9.959 7.498 9.862L6.529 8.894C6.432 8.797 6.301 8.742 6.164 8.742C6.028 8.742 5.897 8.797 5.8 8.893C5.703 8.99 5.649 9.121 5.649 9.258C5.649 9.395 5.703 9.526 5.8 9.623L7.498 11.321C7.699 11.522 8.025 11.522 8.227 11.321L12.245 7.302C12.447 7.101 12.447 6.775 12.245 6.573Z" fill="#2D2D2D"/></svg>
                                {{ $tip }}
                            </li>
                        @endforeach
                    </ul>
                    {{-- Honest retention line: the copy goes to the try-on provider,
                         which keeps it for up to 72 hours — never "deleted right away",
                         whatever the frame's own wording says. --}}
                    <div class="safe-note only-desktop">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><g transform="translate(2.35 1.35)"><path d="M0.65 3.278L9.655 0.65L18.65 3.278V8.667C18.65 11.429 17.78 14.12 16.165 16.361C14.55 18.601 12.272 20.277 9.652 21.15C7.03 20.277 4.751 18.601 3.135 16.361C1.519 14.12 0.65 11.427 0.65 8.665V3.278Z" stroke="#2D2D2D" stroke-width="1.3" stroke-linejoin="round"/><path d="M5.15 10.15L8.65 13.65L14.65 7.65" stroke="#2D2D2D" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></g></svg>
                        <p>{{ __('dashboard.safe_note') }}</p>
                    </div>

                </div>

                {{-- Result: before / after once a look exists --}}
                <div class="panel" id="result-panel" :class="{ 'm-idle': ! result && genState === 'idle' }">
                    <p class="panel-label only-desktop">{{ __('dashboard.result') }}</p>
                    {{-- Phone: the finished look (Figma 1872:8785) — step pill,
                         before/after, four actions, the piece, the stylist. --}}
                    <p class="m-step m-step-lg only-mobile" id="m-result-step" x-show="result" x-cloak><span class="m-dot m-dot-lg"><img src="{{ asset('images/lk-mob/step-dot-18.svg') }}" width="18" height="18" alt=""><span>3</span></span>{{ __('dashboard.step3') }}</p>
                    {{-- Phone: the frame holds under the top bar and shrinks to half
                         as the page scrolls on; what follows rises under it. --}}
                    <div class="m-shrink-mark" aria-hidden="true"></div>
                    <div class="m-shrink" x-data="shrinkFrame()">
                    <div class="frame-2x3">
                        <div class="compare" x-show="result" x-cloak :style="'--split: ' + split + '%; --zoom: ' + zoom">
                            {{-- Comparing: two looks on the same photo take the before/after places. --}}
                            <img :src="comparing ? compareLook(0)?.url : (beforeUrl || photoUrl)" :alt="comparing ? @jsText(__('dashboard.look_n', ['n' => 1])) : @jsText(__('dashboard.before'))">
                            <img class="after" :src="comparing ? compareLook(1)?.url : result" :alt="comparing ? @jsText(__('dashboard.look_n', ['n' => 2])) : @jsText(__('dashboard.after'))">
                            <span class="m-cmp-caption only-mobile" aria-hidden="true">
                                <span class="cap-kicker">{{ __('dashboard.result_kicker') }}</span>
                                <span class="cap-name" x-text="captionPiece()?.category || captionPiece()?.name"></span>
                            </span>
                            <span class="m-cmp-label is-before only-mobile" aria-hidden="true" x-text="comparing ? @jsText(__('dashboard.look_n', ['n' => 1])) : @jsText(__('dashboard.before'))">{{ __('dashboard.before') }}</span>
                            <span class="m-cmp-label is-after only-mobile" aria-hidden="true" x-text="comparing ? @jsText(__('dashboard.look_n', ['n' => 2])) : @jsText(__('dashboard.after'))">{{ __('dashboard.after') }}</span>
                            <span class="bar"></span>
                            <span class="knob" aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="#2D2D2D" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M6 3l5 5-5 5" stroke="#2D2D2D" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <input type="range" min="0" max="100" x-model="split" aria-label="{{ __('dashboard.compare_slider') }}">
                        </div>
                        <div class="dropzone" x-show="! result && genState === 'idle'">
                            <span class="mark"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2.5l1.7 4.3 4.3 1.7-4.3 1.7L10 14.5l-1.7-4.3L4 8.5l4.3-1.7L10 2.5z" stroke="#2D2D2D" stroke-width="1.2" stroke-linejoin="round"/><path d="M15.5 13l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7.7-1.8z" stroke="#2D2D2D" stroke-width="1" stroke-linejoin="round"/></svg></span>
                            <p class="dz-title">{{ __('dashboard.empty_title') }}</p>
                            <p class="dz-hint">{{ __('dashboard.empty_hint') }}</p>
                            {{-- The try-on itself, in the middle of the empty result
                                 (Figma 1893:9537): photo up, the piece on it, the same
                                 pipeline as the internal page (photo → /generate →
                                 signed status URL). Off until a photo, a piece and
                                 both consents are there, and says which is missing. --}}
                            <button type="button" class="dl-btn dl-btn-dark dz-btn generate-btn only-desktop" id="generate-btn"
                                    :disabled="! canGenerate()" x-on:click="generate()">
                                <span x-text="busy ? @jsText(__('dashboard.creating')) : @jsText(__('dashboard.generate'))">{{ __('dashboard.generate') }}</span>
                            </button>
                            <p class="generate-note only-desktop" x-show="hint" x-cloak x-text="hint"></p>
                        </div>
                        {{-- Zoom on a finished look (Figma 1893:9524): 100–200%, and full screen. --}}
                        <div class="zoom-bar only-desktop" id="zoom-bar" x-show="result" x-cloak>
                            <button type="button" class="zoom-btn" aria-label="{{ __('dashboard.zoom_out') }}" :disabled="zoom <= ZOOM_MIN" x-on:click="zoomBy(-ZOOM_STEP)">
                                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M1 6h10" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                            </button>
                            <span class="zoom-val" aria-live="polite" x-text="Math.round(zoom * 100) + '%'">100%</span>
                            <button type="button" class="zoom-btn" aria-label="{{ __('dashboard.zoom_in') }}" :disabled="zoom >= ZOOM_MAX" x-on:click="zoomBy(ZOOM_STEP)">
                                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M1 6h10M6 1v10" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                            </button>
                            <span class="zoom-sep" aria-hidden="true"></span>
                            <button type="button" class="zoom-btn" aria-label="{{ __('dashboard.fullscreen') }}" x-on:click="toggleFullscreen($el.closest('.frame-2x3'))">
                                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M1 5V1h4M9 1h4v4M13 9v4H9M5 13H1V9" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </div>
                        {{-- Working: in the panel on desktop; a phone gets the
                             same loader full-screen (teleported below). --}}
                        @include('membership.partials.gen-loader', ['id' => 'gen-working', 'class' => 'only-desktop'])
                        {{-- Did not work: the server's own sentence, and a way to try again. --}}
                        <div class="gen-state" x-show="genState === 'failed'" x-cloak id="gen-failed">
                            <p class="dz-title">{{ __('dashboard.failed_title') }}</p>
                            <p class="dz-hint" x-text="genMessage"></p>
                            <button type="button" class="dl-btn dl-btn-outline retry" x-on:click="generate()">{{ __('dashboard.retry') }}</button>
                        </div>
                    </div>
                    </div>

                    {{-- The same photo and piece once more (Figma 1906:11608): a new
                         look, a new credit — the button says so by being a plain
                         generate, never a free "retry". --}}
                    <button type="button" class="dl-btn dl-btn-outline dl-btn-block regen-btn only-desktop" id="regenerate-btn"
                            x-show="result" x-cloak :disabled="! canRegenerate()" x-on:click="regenerate()">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.34-5.66M20 4v4.5h-4.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ __('dashboard.regenerate') }}
                    </button>

                    {{-- Explicit consent before the upload, the same two boxes
                         as the fitting-room page: the photo consent and the
                         Terms, separately, because bundled they are worth
                         nothing. Wording and versions live in config; the
                         boxes show only while consent.required is on. --}}
                    @if (config('consent.required'))
                    <div class="tryon-consent only-desktop" id="tryon-consent" role="group" x-show="! result">
                        <label class="consent-row">
                            <input type="checkbox" id="photo-consent" x-model="consent">
                            <span>{{ config('consent.text') }}</span>
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
                        <label class="consent-row">
                            <input type="checkbox" id="terms-accept" x-model="terms">
                            <span>{{ config('terms.text') }}
                                <a href="{{ config('legal.base_url') }}/terms-of-use" target="_blank" rel="noopener">Read them</a>.</span>
                        </label>
                    </div>
                    @endif
                    {{-- With a look on screen (LK_mob 1453:7176): save it, buy the
                         piece it was made with, or go back for another one. --}}
                    <div class="result-actions only-desktop" id="result-actions" x-show="result" x-cloak>
                        <a class="dl-btn dl-btn-dark dl-btn-block" id="save-look" :href="downloadUrl()" download>
                            <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true"><path d="M9 5.5a2 2 0 1 1 2.6 1.9c-.4.1-.6.5-.6.9v1.4M11 9.7l8.2 5.4c.8.5.4 1.7-.5 1.7H3.3c-.9 0-1.3-1.2-.5-1.7L11 9.7z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            {{ __('dashboard.save_look') }}
                        </a>
                        <a class="dl-btn dl-btn-outline dl-btn-block" id="buy-look" :href="shopUrl()" target="_blank" rel="noopener">
                            <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true"><path d="M3 8.5L4.5 4h13L19 8.5M3 8.5c0 1.2 1 2 2.2 2s2.2-.8 2.2-2c0 1.2 1 2 2.2 2h.8c1.2 0 2.2-.8 2.2-2 0 1.2 1 2 2.2 2s2.2-.8 2.2-2c0 1.2 1 2 2.2 2S19 9.7 19 8.5M3 8.5h16M4.5 10.3V18h13v-7.7M9 18v-4.5h4V18" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
                            {{ __('dashboard.buy_in_shop') }}
                        </a>
                        <button type="button" class="dl-btn dl-btn-outline dl-btn-block" id="try-another" x-on:click="tryAnother()">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M14.933 7.767C14.933 7.959 15.01 8.144 15.146 8.281C15.282 8.417 15.467 8.493 15.66 8.493H22.953V1.207C22.953 1.013 22.876 0.827 22.74 0.69C22.603 0.554 22.417 0.477 22.223 0.477C22.03 0.477 21.844 0.554 21.707 0.69C21.57 0.827 21.493 1.013 21.493 1.207V5.967C20.126 3.965 18.133 2.474 15.827 1.727C13.52 0.981 11.032 1.022 8.751 1.844C6.47 2.665 4.527 4.221 3.227 6.267C1.926 8.312 1.342 10.732 1.566 13.146C1.789 15.56 2.808 17.831 4.463 19.602C6.117 21.374 8.313 22.546 10.706 22.935C13.099 23.323 15.553 22.906 17.683 21.749C19.812 20.591 21.498 18.759 22.473 16.54C22.546 16.36 22.545 16.159 22.472 15.98C22.398 15.8 22.258 15.656 22.081 15.578C21.903 15.5 21.702 15.494 21.52 15.562C21.339 15.629 21.19 15.765 21.107 15.94C20.26 17.869 18.793 19.459 16.939 20.459C15.085 21.46 12.95 21.812 10.874 21.46C8.797 21.109 6.897 20.074 5.475 18.519C4.053 16.965 3.192 14.98 3.027 12.88C2.862 10.78 3.403 8.685 4.564 6.928C5.725 5.17 7.44 3.851 9.437 3.18C11.433 2.508 13.597 2.522 15.584 3.22C17.572 3.919 19.269 5.261 20.407 7.033H15.667C15.57 7.032 15.474 7.051 15.385 7.087C15.296 7.124 15.214 7.178 15.146 7.246C15.078 7.314 15.024 7.396 14.987 7.485C14.951 7.574 14.932 7.67 14.933 7.767Z" fill="currentColor"/></svg>
                            {{ __('dashboard.try_another') }}
                        </button>
                    </div>

                    <div class="m-result only-mobile" id="m-result" x-show="result" x-cloak>
                        <div class="m-tiles">
                            {{-- The look is already in the wardrobe; this takes the member there. --}}
                            <a class="m-tile" id="m-save" href="{{ route('membership.account.wardrobe') }}">
                                <img src="{{ asset('images/lk-mob/result-save.svg') }}" alt="">{{ __('dashboard.save') }}
                            </a>
                            {{-- Compare this photo's looks on the frame above (or open the tray to add one). --}}
                            <button type="button" class="m-tile" id="m-compare" x-on:click="toggleCompare()" :aria-pressed="comparing">
                                <img src="{{ asset('images/lk-mob/result-compare.svg') }}" alt="">{{ __('dashboard.compare') }}
                            </button>
                            <button type="button" class="m-tile" id="m-share" x-on:click="shareLook()">
                                <img src="{{ asset('images/lk-mob/result-share.svg') }}" alt="">{{ __('dashboard.share') }}
                            </button>
                            <a class="m-tile" id="m-download" :href="downloadUrl()" download>
                                <img src="{{ asset('images/lk-mob/result-download.svg') }}" alt="">{{ __('dashboard.download') }}
                            </a>
                        </div>
                        <p class="m-copied" x-show="copied" x-cloak>{{ __('dashboard.copied') }}</p>

                        {{-- This photo's looks (Figma 1873:7711): up to three, two of them
                             on the frame at a time; a tap picks, the cross drops one. --}}
                        <div class="m-cmp-tray" id="m-compare-tray" x-show="looks.length > 1 || trayOpen" x-cloak>
                            <p class="m-cmp-count">{{ __('dashboard.looks') }}<br><b x-text="looks.length"></b> / <span x-text="MAX_LOOKS"></span></p>
                            <div class="m-cmp-thumbs">
                                <template x-for="(look, i) in looks" :key="look.id">
                                    <div class="m-cmp-thumb" :class="{ 'is-picked': comparing && isPicked(look.id) }">
                                        <button type="button" class="m-cmp-pick" x-on:click="pickLook(look.id); if (looks.length > 1 && ! comparing) toggleCompare()"
                                                :aria-pressed="comparing && isPicked(look.id)" :aria-label="@jsText(__('dashboard.compare_look')).replace(':n', i + 1)">
                                            <img :src="look.url" alt="">
                                        </button>
                                        <button type="button" class="m-cmp-remove" x-on:click="removeLook(look.id)" :aria-label="@jsText(__('dashboard.remove_look')).replace(':n', i + 1)">
                                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><circle cx="7" cy="7" r="7" fill="#6B6B6B"/><path d="M4.75 4.75l4.5 4.5M9.25 4.75l-4.5 4.5" stroke="#FFFFFF" stroke-width="1" stroke-linecap="round"/></svg>
                                        </button>
                                    </div>
                                </template>
                            </div>
                            <div class="m-cmp-actions">
                                <button type="button" class="m-btn m-btn-outline m-cmp-add" id="m-add-look" x-show="looks.length < MAX_LOOKS" x-on:click="tryAnother()">
                                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M6 1v10M1 6h10" stroke="#2D2D2D" stroke-width="1" stroke-linecap="round"/></svg>
                                    {{ __('dashboard.add') }}
                                </button>
                                <button type="button" class="m-btn m-btn-dark" id="m-compare-go" x-on:click="toggleCompare()"
                                        :disabled="looks.length < 2" x-text="comparing ? @jsText(__('dashboard.before_after')) : @jsText(__('dashboard.compare'))">{{ __('dashboard.compare') }}</button>
                            </div>
                        </div>

                        {{-- The piece the look was made with: brand and shop link from the product row. --}}
                        <div class="m-item" id="m-item" x-show="chosen()">
                            <div class="m-item-pic"><img :src="chosen()?.images[0]" :alt="chosen()?.name"></div>
                            <div class="m-item-text">
                                <p class="m-item-brand" x-text="chosen()?.brand"></p>
                                <p class="m-item-name" x-text="chosen()?.name"></p>
                                <p class="m-item-price" x-text="chosen()?.price"></p>
                            </div>
                            <button type="button" class="m-item-like" :aria-pressed="isLiked(resultProduct)" aria-label="{{ __('dashboard.like') }}"
                                    x-on:click="toggleLike(resultProduct)">
                                <img src="{{ asset('images/lk-mob/heart-filled.svg') }}" width="18" height="18" alt="" x-show="isLiked(resultProduct)">
                                <img src="{{ asset('images/lk-mob/result-heart.svg') }}" width="18" height="18" alt="" x-show="! isLiked(resultProduct)">
                            </button>
                            <div class="m-item-actions">
                                <button type="button" class="m-btn m-btn-outline" id="m-try-another" x-on:click="tryAnother()">{{ __('dashboard.try_another_short') }}</button>
                                <a class="m-btn m-btn-dark" id="m-shop" :href="shopUrl()" target="_blank" rel="noopener">{{ __('dashboard.buy') }}</a>
                            </div>
                        </div>

                        {{-- The stylist card (Figma 1873:7608) is always part of the
                             result; with no booking link set the button stays inert
                             rather than pointing somewhere made up. --}}
                        <div class="m-ask" id="m-ask">
                            <img class="m-ask-pic" src="{{ asset('images/lk-mob/ask-stylist.png') }}" alt="">
                            <div class="m-ask-text">
                                <p class="m-ask-title">{{ __('dashboard.ask_title') }}</p>
                                <p class="m-ask-sub">{{ __('dashboard.ask_sub') }}</p>
                            </div>
                            @if ($bookingUrl)
                                <a class="m-btn m-btn-dark" href="{{ $bookingUrl }}" target="_blank" rel="noopener">{{ __('dashboard.ask_cta') }}</a>
                            @else
                                <button type="button" class="m-btn m-btn-dark" disabled>{{ __('dashboard.ask_cta') }}</button>
                            @endif
                        </div>
                    </div>
                    {{-- A phone's loader covers the screen: out to <body> so no
                         ancestor's stacking or transform can clip it. --}}
                    <template x-teleport="body">
                        <div class="dl" x-effect="document.documentElement.classList.toggle('gen-lock', busy)">
                            @include('membership.partials.gen-loader', ['id' => 'gen-working-mobile', 'class' => 'gen-loader-full only-mobile'])
                        </div>
                    </template>
                    {{-- The paywall lives on the layout's component; a method here
                         cannot reach it, the template can. --}}
                    <span x-effect="if (paywall) { paywall = false; openModal('paywall'); }"></span>
                </div>

                {{-- Catalog: search, brand, category chips, pick grid --}}
                <div class="panel" id="catalog-panel">
                    <p class="panel-label only-desktop">{{ __('dashboard.pick_clothes') }}</p>
                    <div class="catalog-filters">
                    <label class="catalog-field">
                        <svg class="only-desktop" width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="6" stroke="#2D2D2D" stroke-width="1.3"/><path d="M14.5 14.5L19 19" stroke="#2D2D2D" stroke-width="1.3" stroke-linecap="round"/></svg>
                        <input type="search" placeholder="{{ __('dashboard.search_long') }}" :placeholder="phone ? @jsText(__('dashboard.search_short')) : @jsText(__('dashboard.search_long'))"
                               aria-label="{{ __('dashboard.search_long') }}" x-model.debounce.150ms="query">
                    </label>
                    {{-- Brand picker: a searchable multi-select built the same
                         way as the country picker on the sign-in form (a real
                         <select multiple> cannot be styled or searched). --}}
                    <div class="brand-picker" id="brand-picker" x-on:click.outside="brandOpen = false" x-on:keydown.escape="brandOpen = false">
                        <div class="catalog-field brand-trigger" :class="{ open: brandOpen }" role="button" tabindex="0"
                             aria-haspopup="listbox" :aria-expanded="brandOpen"
                             x-on:click="toggleBrands()" x-on:keydown.enter.prevent="toggleBrands()" x-on:keydown.space.prevent="toggleBrands()">
                            <svg class="only-desktop" width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true"><path d="M8 3.5c0 1.5 1.3 2.5 3 2.5s3-1 3-2.5M8.5 5.5L3 9l2 3 2.5-1v8h7v-8l2.5 1 2-3-5.5-3.5" stroke="#2D2D2D" stroke-width="1.3" stroke-linejoin="round"/></svg>
                            <span class="brand-value">
                                <span class="placeholder" x-show="brands.length === 0">{{ __('dashboard.brand_placeholder') }}</span>
                                <template x-for="b in brands" :key="b">
                                    <span class="brand-chip">
                                        <span x-text="b"></span>
                                        <button type="button" class="remove" :aria-label="@jsText(__('dashboard.remove_brand')).replace(':brand', b)" x-on:click.stop="toggleBrand(b)">
                                            <svg width="10" height="10" viewBox="0 0 10 10" fill="none" aria-hidden="true"><path d="M2 2l6 6M8 2l-6 6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
                                        </button>
                                    </span>
                                </template>
                            </span>
                            <svg class="caret only-desktop" width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true"><path d="M6 8.5l5 5 5-5" stroke="#2D2D2D" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </div>
                        <div class="brand-menu" x-show="brandOpen" x-cloak>
                            <input class="search" type="search" placeholder="{{ __('dashboard.find_brand') }}" aria-label="{{ __('dashboard.find_brand') }}"
                                   x-ref="brandSearch" x-model="brandQuery">
                            <div class="list" role="listbox" aria-multiselectable="true">
                                <template x-for="b in brandOptions()" :key="b">
                                    <button type="button" class="brand-option" role="option"
                                            :class="{ active: brands.includes(b) }" :aria-selected="brands.includes(b)"
                                            x-on:click="toggleBrand(b)">
                                        <span class="tick">
                                            <svg width="10" height="10" viewBox="0 0 12 12" fill="none" x-show="brands.includes(b)"><path d="M2.5 6.2l2.4 2.3 4.6-4.8" stroke="#FFFFFF" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </span>
                                        <span class="label" x-text="b"></span>
                                        <span class="count" x-text="brandCounts[b]"></span>
                                    </button>
                                </template>
                                <p class="empty" x-show="brandOptions().length === 0">{{ __('dashboard.nothing_found') }}</p>
                            </div>
                            <div class="foot">
                                <button type="button" class="btn-quiet" x-on:click="brands = []" x-show="brands.length > 0">{{ __('dashboard.clear') }}</button>
                                <button type="button" class="btn-quiet" x-on:click="brandOpen = false">{{ __('dashboard.done') }}</button>
                            </div>
                        </div>
                    </div>
                    </div>

                    {{-- Category chips come from the feed: one per category the
                         verified products are filed under (CategoryChips), so a
                         chip never filters down to nothing. "Усі" clears it. --}}
                    <div class="chips" id="catalog-categories" x-data="chipRow()">
                        {{-- The member's season, when there is one and pieces suit it --}}
                        @if ($myColourIds)
                            <button type="button" class="chip" id="chip-my-colours" :class="{ active: mine }"
                                    x-on:click="mine = ! mine">{{ __('dashboard.my_colours') }}</button>
                        @endif
                        @if ($categories)
                            <button type="button" class="chip" :class="{ active: category === '' }"
                                    x-on:click="category = ''">{{ __('dashboard.all') }}</button>
                        @endif
                        @foreach ($categories as $category)
                            <button type="button" class="chip" data-category="{{ $category }}" :class="{ active: category === @js($category) }"
                                    x-on:click="category = category === @js($category) ? '' : @js($category)">{{ $category }}</button>
                        @endforeach
                        {{-- Desktop: what does not fit the row, listed here --}}
                        <span class="chips-more" id="catalog-categories-more" x-ref="more" hidden
                              x-on:click.outside="moreOpen = false" x-on:keydown.escape="moreOpen = false">
                            <button type="button" class="chip" :class="{ active: more.includes(category) }"
                                    aria-haspopup="listbox" :aria-expanded="moreOpen" x-on:click="moreOpen = ! moreOpen">
                                <span x-text="more.includes(category) ? category : @jsText(__('dashboard.more'))">{{ __('dashboard.more') }}</span>
                                <svg width="14" height="14" viewBox="0 0 22 22" fill="none" aria-hidden="true"><path d="M6 8.5l5 5 5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <div class="brand-menu" x-show="moreOpen" x-cloak>
                                <div class="list" role="listbox">
                                    <template x-for="c in more" :key="c">
                                        <button type="button" class="brand-option" role="option"
                                                :class="{ active: category === c }" :aria-selected="category === c"
                                                x-on:click="category = category === c ? '' : c; moreOpen = false">
                                            <span class="label" x-text="c"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </span>
                    </div>

                    <div class="pick-grid" id="catalog-grid">
                        @forelse ($products as $product)
                            {{-- The whole card picks the piece (as before); the button is
                                 its keyboard-reachable face, the heart marks a favourite,
                                 and the picture opens the detail modal. --}}
                            <article class="pick-card"
                                     data-brand="{{ $product->brand_name }}"
                                     data-category="{{ $product->category_label }}"
                                     :class="{ selected: selected === {{ $product->id }} }"
                                     x-show="matches(@js($product->brand_name), @js($product->product_name), @js((string) $product->category_label), {{ $product->id }})"
                                     x-on:click="toggle({{ $product->id }})">
                                <button type="button" class="like" :class="{ liked: isLiked({{ $product->id }}) }"
                                        :aria-pressed="isLiked({{ $product->id }})" aria-label="{{ __('dashboard.like') }}"
                                        x-on:click.stop="toggleLike({{ $product->id }})">
                                    <svg class="only-desktop" width="28" height="28" viewBox="0 0 28 28" fill="none" aria-hidden="true"><path d="M25.667 5.942C24.624 3.803 21.622 2.053 18.13 3.072C16.461 3.554 15.005 4.588 14 6.004C12.995 4.588 11.539 3.554 9.87 3.072C6.37 2.069 3.376 3.803 2.333 5.942C0.871 8.937 1.478 12.304 4.138 15.952C6.222 18.807 9.201 21.7 13.526 25.06C13.662 25.167 13.831 25.225 14.004 25.225C14.177 25.225 14.346 25.167 14.482 25.06C18.799 21.708 21.786 18.838 23.87 15.952C26.522 12.304 27.129 8.937 25.667 5.942Z" fill="currentColor"/></svg>
                                </button>
                                <button type="button" class="pic" aria-label="{{ __('dashboard.details_of', ['name' => $product->product_name]) }}" title="{{ __('dashboard.details') }}"
                                        x-on:click.stop="openDetail({{ $product->id }})"><img src="{{ $product->image_flat_url }}" alt="" loading="lazy"></button>
                                <span class="brand">{{ $product->brand_name }}</span>
                                <span class="name">{{ $product->product_name }}</span>
                                <span class="price">{{ number_format((float) $product->price_aed, 2) }} AED</span>
                                <button type="button" class="pick" :aria-pressed="selected === {{ $product->id }}"
                                        x-on:click.stop="toggle({{ $product->id }})"
                                        x-text="selected === {{ $product->id }} ? @jsText(__('dashboard.selected')) : (phone ? @jsText(__('dashboard.try_on')) : @jsText(__('dashboard.pick')))">{{ __('dashboard.pick') }}</button>
                            </article>
                        @empty
                            <p class="pick-empty" id="catalog-empty">{{ __('dashboard.catalog_empty') }}</p>
                        @endforelse
                    </div>
                </div>

                {{-- The piece behind the look on screen (Figma 1893:9143): it
                     takes the catalog's place once a look is ready — beside the
                     result from 1200px, under it on a tablet — and "Приміряти
                     іншу річ" brings the catalog back. The frame's size / colour
                     line is left out: the feeds carry neither. --}}
                <div class="panel chosen-panel" id="chosen-panel" x-show="result && chosen()" x-cloak>
                    <p class="panel-label">{{ __('dashboard.chosen_piece') }}</p>
                    <div class="chosen-box">
                        <div class="chosen-card">
                            <div class="pic"><img :src="chosen()?.images[0]" :alt="chosen()?.name"></div>
                            <div class="text">
                                <p class="brand" x-text="chosen()?.brand"></p>
                                <p class="name" x-text="chosen()?.name"></p>
                                <p class="price" x-text="chosen()?.price"></p>
                                <button type="button" class="chosen-fav" :class="{ liked: isLiked(resultProduct) }"
                                        :aria-pressed="isLiked(resultProduct)" x-on:click="toggleLike(resultProduct)">
                                    <svg width="24" height="24" viewBox="0 0 28 28" fill="none" aria-hidden="true"><path d="M25.667 5.942C24.624 3.803 21.622 2.053 18.13 3.072C16.461 3.554 15.005 4.588 14 6.004C12.995 4.588 11.539 3.554 9.87 3.072C6.37 2.069 3.376 3.803 2.333 5.942C0.871 8.937 1.478 12.304 4.138 15.952C6.222 18.807 9.201 21.7 13.526 25.06C13.662 25.167 13.831 25.225 14.004 25.225C14.177 25.225 14.346 25.167 14.482 25.06C18.799 21.708 21.786 18.838 23.87 15.952C26.522 12.304 27.129 8.937 25.667 5.942Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                                    <span x-text="isLiked(resultProduct) ? @jsText(__('dashboard.in_favorites')) : @jsText(__('dashboard.add_favorites'))">{{ __('dashboard.add_favorites') }}</span>
                                </button>
                            </div>
                        </div>
                        <div class="result-actions">
                            <a class="dl-btn dl-btn-dark dl-btn-block" id="chosen-buy" :href="shopUrl()" target="_blank" rel="noopener">
                                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M11 3h6v6M17 3l-8 8M15 12v4a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                {{ __('dashboard.go_to_shop') }}
                            </a>
                            <a class="dl-btn dl-btn-outline dl-btn-block" id="save-to-wardrobe" href="{{ route('membership.account.wardrobe') }}">
                                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3 3h14v14H3zM10 3v14M7 9v2M13 9v2" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                                {{ __('dashboard.save_wardrobe') }}
                            </a>
                            <button type="button" class="dl-btn dl-btn-outline dl-btn-block" id="chosen-try-another" x-on:click="tryAnother()">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.34-5.66M20 4v4.5h-4.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                {{ __('dashboard.try_another') }}
                            </button>
                        </div>
                    </div>
                    {{-- The look itself (the phone's four tiles): to the wardrobe,
                         compared with this photo's other looks, shared, downloaded. --}}
                    <div class="chosen-tools" id="chosen-tools">
                        <a class="chosen-tool" id="chosen-save" href="{{ route('membership.account.wardrobe') }}">
                            <img src="{{ asset('images/lk-mob/result-save.svg') }}" width="24" height="24" alt="">
                            <span>{{ __('dashboard.save') }}</span>
                        </a>
                        <button type="button" class="chosen-tool" id="chosen-compare" x-on:click="toggleCompare()" :aria-pressed="comparing">
                            <img src="{{ asset('images/lk-mob/result-compare.svg') }}" width="24" height="24" alt="">
                            <span>{{ __('dashboard.compare') }}</span>
                        </button>
                        <button type="button" class="chosen-tool" id="chosen-share" x-on:click="shareLook()">
                            <img src="{{ asset('images/lk-mob/result-share.svg') }}" width="24" height="24" alt="">
                            <span>{{ __('dashboard.share') }}</span>
                        </button>
                        <a class="chosen-tool" id="chosen-download" :href="downloadUrl()" download>
                            <img src="{{ asset('images/lk-mob/result-download.svg') }}" width="24" height="24" alt="">
                            <span>{{ __('dashboard.download') }}</span>
                        </a>
                    </div>
                    <p class="chosen-note" x-show="copied" x-cloak>{{ __('dashboard.copied') }}</p>
                    {{-- The feed's description, folded until asked for. --}}
                    <div class="chosen-details" x-data="{ open: false }" x-show="chosen()?.description">
                        <button type="button" class="chosen-details-toggle" :aria-expanded="open" x-on:click="open = ! open">
                            {{ __('dashboard.product_details') }}
                            <svg :class="{ open }" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <p class="chosen-desc" x-show="open" x-cloak x-text="chosen()?.description"></p>
                    </div>
                </div>
            </div>

            {{-- Step 3 below the workspace, desktop and tablet (Figma 1893:9938,
                 1906:11245, 1901:11218): this photo's looks to compare, the
                 pieces that go with the one on screen, and the stylist. --}}
            <div class="look-extras only-desktop" id="look-extras" x-show="result" x-cloak>
                <div class="cmp-strip" id="compare-strip">
                    <div class="cmp-title">
                        <h2>{{ __('dashboard.compare_looks') }}</h2>
                        <p class="cmp-count"><b x-text="looks.length"></b> / <span x-text="MAX_LOOKS"></span></p>
                    </div>
                    <div class="cmp-looks">
                        <template x-for="(look, i) in looks" :key="look.id">
                            <div class="cmp-look" :class="{ 'is-picked': comparing && isPicked(look.id) }">
                                <button type="button" class="cmp-pick" x-on:click="pickLook(look.id); if (looks.length > 1 && ! comparing) toggleCompare()"
                                        :aria-pressed="comparing && isPicked(look.id)" :aria-label="@jsText(__('dashboard.compare_look')).replace(':n', i + 1)">
                                    <img :src="look.url" alt="">
                                </button>
                                <button type="button" class="cmp-remove" x-on:click="removeLook(look.id)" :aria-label="@jsText(__('dashboard.remove_look')).replace(':n', i + 1)">
                                    <svg width="32" height="32" viewBox="0 0 32 32" fill="none" aria-hidden="true"><circle cx="16" cy="16" r="15" fill="#2D2D2D" stroke="#FFFFFF" stroke-width="1"/><path d="M11.5 11.5l9 9M20.5 11.5l-9 9" stroke="#FFFFFF" stroke-width="1.3" stroke-linecap="round"/></svg>
                                </button>
                            </div>
                        </template>
                        <button type="button" class="cmp-add" id="add-look" x-show="looks.length < MAX_LOOKS" x-on:click="tryAnother()">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 4v16M4 12h16" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                            {{ __('dashboard.add_look') }}
                        </button>
                    </div>
                    <button type="button" class="dl-btn dl-btn-dark cmp-go" id="compare-go" x-on:click="toggleCompare()" :disabled="looks.length < 2"
                            x-text="comparing ? @jsText(__('dashboard.before_after')) : @jsText(__('dashboard.compare_two'))">{{ __('dashboard.compare_two') }}</button>
                </div>

                {{-- What goes with the piece on screen: the catalog's own pairings
                     (the detail modal's "Стилізуй з цим"), brand and shop link
                     from each product row. --}}
                <div class="pair-row" id="look-pairs" x-show="resultPairs().length">
                    <template x-for="p in resultPairs()" :key="p.id">
                        <div class="pair-card">
                            <button type="button" class="pic" x-on:click="openDetail(p.id)" :aria-label="@jsText(__('dashboard.details_of')).replace(':name', p.name)"><img :src="p.images[0]" alt=""></button>
                            <div class="info">
                                <p class="brand" x-text="p.brand"></p>
                                <p class="name" x-text="p.name"></p>
                                <p class="price" x-text="p.price"></p>
                                <div class="buttons">
                                    <a class="pair-shop" :href="routes.shop.replace('/shop/0', '/shop/' + p.id)" target="_blank" rel="noopener">{{ __('dashboard.buy') }}</a>
                                    <button type="button" class="pair-like" :class="{ liked: isLiked(p.id) }" :aria-pressed="isLiked(p.id)"
                                            aria-label="{{ __('dashboard.like') }}" x-on:click="toggleLike(p.id)">
                                        <svg width="22" height="22" viewBox="0 0 28 28" fill="none" aria-hidden="true"><path d="M25.667 5.942C24.624 3.803 21.622 2.053 18.13 3.072C16.461 3.554 15.005 4.588 14 6.004C12.995 4.588 11.539 3.554 9.87 3.072C6.37 2.069 3.376 3.803 2.333 5.942C0.871 8.937 1.478 12.304 4.138 15.952C6.222 18.807 9.201 21.7 13.526 25.06C13.662 25.167 13.831 25.225 14.004 25.225C14.177 25.225 14.346 25.167 14.482 25.06C18.799 21.708 21.786 18.838 23.87 15.952C26.522 12.304 27.129 8.937 25.667 5.942Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- The stylist (the phone's card, at desktop scale); with no
                     booking link set the button stays inert. --}}
                <div class="ask-strip" id="ask-strip">
                    <img class="ask-pic" src="{{ asset('images/lk-mob/ask-stylist.png') }}" alt="">
                    <div class="ask-text">
                        <h2>{{ __('dashboard.ask_title') }}</h2>
                        <p>{{ __('dashboard.ask_sub') }}</p>
                    </div>
                    @if ($bookingUrl)
                        <a class="dl-btn dl-btn-dark" href="{{ $bookingUrl }}" target="_blank" rel="noopener">{{ __('dashboard.ask_cta') }}</a>
                    @else
                        <button type="button" class="dl-btn dl-btn-dark" disabled>{{ __('dashboard.ask_cta') }}</button>
                    @endif
                </div>
            </div>
        </section>

        {{-- ============ Product detail modal (Desk_lk 1661:8117) ============
             Opened by a card's picture ("Детальніше"). Everything in it comes from the
             product row (productDetails); the gallery column only appears when
             the feed gave more than one photo. Teleported to <body>: the
             section's rise animation leaves a transform, which would make a
             fixed overlay fixed to the section instead of the screen. --}}
        <template x-teleport="body">
        <div class="pd dl only-desktop" id="product-detail" role="dialog" aria-modal="true" aria-labelledby="pd-name"
             x-show="detail" x-cloak x-on:keydown.escape.window="closeDetail()">
            <div class="modal-scrim" x-on:click="closeDetail()"></div>
            <template x-if="detail">
                <div class="pd-wrap">
                    <button type="button" class="pd-close" x-ref="detailClose" aria-label="{{ __('common.close') }}" x-on:click="closeDetail()">
                        <svg width="52" height="52" viewBox="0 0 52 52" fill="none" aria-hidden="true"><circle cx="26" cy="26" r="19.5" stroke="currentColor" stroke-width="1.5"/><path d="M19.5 19.5l13 13M32.5 19.5l-13 13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    </button>
                    <div class="pd-card">
                        <div class="pd-gallery">
                            <div class="pd-thumbs" x-show="detail.images.length > 1">
                                <template x-for="(src, i) in detail.images" :key="src">
                                    <button type="button" class="pd-thumb" :class="{ active: detailImage === i }"
                                            :aria-label="@jsText(__('dashboard.photo_n')).replace(':n', i + 1)" x-on:click="detailImage = i">
                                        <img :src="src" alt="" loading="lazy">
                                    </button>
                                </template>
                            </div>
                            <div class="pd-main"><img :src="detail.images[detailImage]" :alt="detail.name"></div>
                        </div>

                        <div class="pd-info">
                            <div class="pd-brand" x-text="detail.brand"></div>
                            <div class="pd-id" x-text="'ID ' + detail.externalId"></div>
                            <h2 class="pd-name" id="pd-name" x-text="detail.name"></h2>
                            <div class="pd-price" x-text="detail.price"></div>
                            <template x-if="detail.description">
                                <div>
                                    <hr>
                                    <div class="pd-label" style="margin-top: 13px;">{{ __('dashboard.description') }}</div>
                                    <p class="pd-desc" x-text="detail.description"></p>
                                </div>
                            </template>
                            <div class="pd-actions">
                                <button type="button" class="dl-btn dl-btn-dark dl-btn-block" id="pd-pick"
                                        x-on:click="pickFromDetail()">{{ __('dashboard.pick_for_tryon') }}</button>
                                <button type="button" class="dl-btn dl-btn-outline dl-btn-block" id="pd-like"
                                        :aria-pressed="isLiked(detail.id)" x-on:click="toggleLike(detail.id)">
                                    <svg width="24" height="24" viewBox="0 0 28 28" fill="none" aria-hidden="true"><path d="M25.667 5.942C24.624 3.803 21.622 2.053 18.13 3.072C16.461 3.554 15.005 4.588 14 6.004C12.995 4.588 11.539 3.554 9.87 3.072C6.37 2.069 3.376 3.803 2.333 5.942C0.871 8.937 1.478 12.304 4.138 15.952C6.222 18.807 9.201 21.7 13.526 25.06C13.662 25.167 13.831 25.225 14.004 25.225C14.177 25.225 14.346 25.167 14.482 25.06C18.799 21.708 21.786 18.838 23.87 15.952C26.522 12.304 27.129 8.937 25.667 5.942Z" :fill="isLiked(detail.id) ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.6"/></svg>
                                    <span x-text="isLiked(detail.id) ? @jsText(__('dashboard.in_favorites')) : @jsText(__('dashboard.add_to_favorites'))"></span>
                                </button>
                            </div>
                        </div>

                        <span class="pd-rule" aria-hidden="true"></span>

                        <div class="pd-pairs" id="pd-pairs">
                            <h3>{{ __('dashboard.style_with') }}</h3>
                            <p class="sub">{{ __('dashboard.style_with_sub') }}</p>
                            <div class="pd-pair-grid" x-show="detailPairs().length">
                                <template x-for="p in detailPairs()" :key="p.id">
                                    <button type="button" class="pd-pair" x-on:click="openDetail(p.id)">
                                        <span class="pic"><img :src="p.images[0]" alt="" loading="lazy"></span>
                                        <span class="brand" x-text="p.brand"></span>
                                        <span class="name" x-text="p.name"></span>
                                        <span class="price" x-text="p.price"></span>
                                    </button>
                                </template>
                            </div>
                            <p class="none" x-show="! detailPairs().length">{{ __('dashboard.pairs_none') }}</p>
                        </div>
                    </div>
                </div>
            </template>
        </div>
        </template>

        {{-- ============ The phone's product card (Figma 2093:9429) ============
             The same `detail` as the modal above, drawn as the phone's card.
             Its button picks the piece and, when the photo is in, starts the
             look at once. --}}
        <template x-teleport="body">
        <div class="pm dl only-mobile" id="m-product-detail" role="dialog" aria-modal="true" aria-labelledby="pm-name"
             x-show="detail" x-cloak x-on:keydown.escape.window="closeDetail()">
            <div class="pm-scrim" x-on:click="closeDetail()"></div>
            <template x-if="detail">
                <div class="pm-card">
                    <div class="pm-pics">
                        <div class="pm-track" x-ref="pmTrack" x-on:scroll.debounce.80ms="detailImage = Math.round($el.scrollLeft / Math.max(1, $el.clientWidth))">
                            <template x-for="(src, i) in detail.images" :key="src">
                                <img :src="src" :alt="i === 0 ? detail.name : ''" :loading="i ? 'lazy' : 'eager'">
                            </template>
                        </div>
                        <button type="button" class="pm-round pm-back" x-ref="detailBack" aria-label="{{ __('common.back') }}" x-on:click="closeDetail()">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"></path></svg>
                        </button>
                        <button type="button" class="pm-round pm-like" id="m-pd-like" :aria-label="isLiked(detail.id) ? @jsText(__('dashboard.remove_favorites')) : @jsText(__('dashboard.add_to_favorites'))"
                                :aria-pressed="isLiked(detail.id)" x-on:click="toggleLike(detail.id)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"></path></svg>
                        </button>
                        <span class="pm-count" x-show="detail.images.length > 1" x-text="@jsText(__('dashboard.n_of')).replace(':n', detailImage + 1).replace(':total', detail.images.length)"></span>
                    </div>
                    <div class="pm-bars" x-show="detail.images.length > 1">
                        <template x-for="(src, i) in detail.images" :key="src">
                            <button type="button" :class="{ active: detailImage === i }" :aria-label="@jsText(__('dashboard.photo_n')).replace(':n', i + 1)" x-on:click="showDetailImage(i)"></button>
                        </template>
                    </div>
                    <div class="pm-info" :class="{ solo: detail.images.length < 2 }">
                        <div class="pm-row">
                            <span class="pm-brand" x-text="detail.brand"></span>
                            <span class="pm-id" x-text="'ID ' + detail.externalId"></span>
                        </div>
                        <h2 class="pm-name" id="pm-name" x-text="detail.name"></h2>
                        <div class="pm-price" x-text="detail.price"></div>
                    </div>
                    <button type="button" class="pm-go" id="m-pd-go" x-on:click="generateFromDetail()"
                            x-text="canGenerate(detail.id) ? @jsText(__('dashboard.generate_look')) : @jsText(__('dashboard.pick_for_tryon'))">{{ __('dashboard.pick_for_tryon') }}</button>
                </div>
            </template>
        </div>
        </template>

        {{-- ============ Share strip (frame 1419:8679) ============ --}}
        <section class="share-strip" id="share-section">
            <div>
                <h2>{{ __('dashboard.share_title') }}</h2>
                <p class="lede">{{ __('dashboard.share_lede') }}</p>
            </div>
            <div class="share-row">
                <span class="label">{{ __('dashboard.share_label') }}</span>
                @foreach ($shareNetworks as $key => $network)
                    <button type="button" class="share-btn" title="{{ $network }}" aria-label="{{ __('dashboard.share_on', ['network' => $network]) }}"
                            :disabled="! result" x-on:click="share(@js($key))">
                        @include('membership.partials.share-icon', ['network' => $key])
                    </button>
                @endforeach
                <span class="share-note" x-show="copied" x-cloak>{{ __('dashboard.copied') }}</span>
            </div>
        </section>

        {{-- ============ Buy this look (frame 1423:9000) ============ --}}
        <section class="dl-section" id="shop-section">
            <div class="dl-head">
                <div>
                    <div class="eyebrow">{{ __('dashboard.buy_title') }}</div>
                    <h1>{{ __('dashboard.buy_title') }}</h1>
                    <p class="lede">{{ __('dashboard.buy_lede') }}</p>
                </div>
            </div>
            <div class="shop-grid">
                @forelse ($lookProducts as $product)
                    {{-- Brand and shop link come from the product row (never from the template). --}}
                    <article class="shop-card">
                        <div class="pic"><img src="{{ $product->image_flat_url }}" alt="{{ $product->product_name }}" loading="lazy"></div>
                        <div class="text">
                            <div class="brand">{{ $product->brand_name }}</div>
                            <h3 class="name">{{ $product->product_name }}</h3>
                            <div class="price">{{ number_format((float) $product->price_aed, 2) }} AED</div>
                        </div>
                        <a class="dl-btn dl-btn-dark dl-btn-block" href="{{ route('membership.account.shop', ['product' => $product->id, 'from' => 'catalog']) }}" target="_blank" rel="noopener">
                            <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true"><path d="M11 3l1.9 4.6L17.5 9.5l-4.6 1.9L11 16l-1.9-4.6L4.5 9.5l4.6-1.9L11 3z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M17 14l.7 1.6 1.6.7-1.6.7L17 18.6l-.7-1.6-1.6-.7 1.6-.7L17 14z" stroke="currentColor" stroke-width="1.1" stroke-linejoin="round"/></svg>
                            {{ __('dashboard.go_to_shop') }}
                        </a>
                    </article>
                @empty
                    <p class="pick-empty" id="shop-empty">{{ __('dashboard.shop_empty') }}</p>
                @endforelse
            </div>
        </section>

        {{-- ============ Consultation (frame 1423:9017) ============ --}}
        <section class="consult" id="consult-section">
            <div class="visual">
                {{-- the stylist's photo (public/images/consultation.jpg, tracked). Without the file the hatched surface stands in. --}}
                @if (file_exists(public_path('images/consultation.jpg')))
                    <img src="{{ asset('images/consultation.jpg') }}" alt="{{ __('dashboard.consult_alt') }}">
                @else
                    <span class="caption">Atelier Fit</span>
                @endif
            </div>
            <div class="text">
                <h2>{{ __('dashboard.consult_title') }}</h2>
                <div class="copy">
                    <p>{{ __('dashboard.consult_p1') }}</p>
                    <p>{{ __('dashboard.consult_p2') }}</p>
                </div>
                @if ($bookingUrl)
                    <a class="dl-btn dl-btn-white dl-btn-block" href="{{ $bookingUrl }}" target="_blank" rel="noopener">{{ __('dashboard.consult_cta') }}</a>
                @else
                    <button type="button" class="dl-btn dl-btn-white dl-btn-block" disabled title="{{ __('dashboard.booking_soon') }}">{{ __('dashboard.consult_cta') }}</button>
                @endif
            </div>
        </section>

        {{-- ============ Lead cards (frame 1425:6372) ============ --}}
        <section class="cta-grid" id="cta-cards">
            <div class="cta-card">
                <div class="text">
                    <h3>{{ __('dashboard.news_title') }}</h3>
                    <p>{{ __('dashboard.news_text') }}</p>
                </div>
                {{-- No mailing list exists yet: submitting says so instead of pretending. --}}
                <form class="cta-form" x-on:submit.prevent="comingSoon('subscribe')">
                    <input type="text" placeholder="{{ __('dashboard.contact_placeholder') }}" aria-label="{{ __('dashboard.contact_label') }}" autocomplete="off">
                    <button type="submit" class="dl-btn dl-btn-dark">{{ __('dashboard.subscribe') }}</button>
                </form>
                <p class="note" x-text="note === 'subscribe' ? @jsText(__('dashboard.subscribe_soon')) : @jsText(__('dashboard.no_spam'))">{{ __('dashboard.no_spam') }}</p>
            </div>
            <div class="cta-card">
                <div class="text">
                    <h3>{{ __('dashboard.ready_title') }}</h3>
                    <p>{{ __('dashboard.ready_text') }}</p>
                </div>
                @if ($bookingUrl)
                    <a class="dl-btn dl-btn-dark dl-btn-block" href="{{ $bookingUrl }}" target="_blank" rel="noopener">{{ __('dashboard.ready_cta') }}</a>
                @else
                    <button type="button" class="dl-btn dl-btn-dark dl-btn-block" disabled title="{{ __('dashboard.booking_soon') }}">{{ __('dashboard.ready_cta') }}</button>
                @endif
                <p class="note"></p>
            </div>
            <div class="cta-card">
                <div class="text">
                    <h3>{{ __('dashboard.lookbook_title') }}</h3>
                    <p>{{ __('dashboard.lookbook_text') }}</p>
                </div>
                <form class="cta-form" x-on:submit.prevent="comingSoon('lookbook')">
                    <input type="text" placeholder="{{ __('dashboard.contact_placeholder') }}" aria-label="{{ __('dashboard.contact_label') }}" autocomplete="off">
                    <button type="submit" class="dl-btn dl-btn-dark">{{ __('dashboard.send') }}</button>
                </form>
                <p class="note" x-text="note === 'lookbook' ? @jsText(__('dashboard.lookbook_soon')) : @jsText(__('dashboard.lookbook_note'))">{{ __('dashboard.lookbook_note') }}</p>
            </div>
        </section>

        {{-- ============ Plans (frame 1430:6601) ============ --}}
        <section class="dl-section" id="plans-section">
            <div class="dl-head">
                <div>
                    <div class="eyebrow">{{ __('dashboard.plans_eyebrow') }}</div>
                    <h1>{{ __('dashboard.plans_title') }}</h1>
                    <p class="lede">{{ __('dashboard.plans_lede') }}</p>
                </div>
            </div>
            {{-- LK_mob: one card at a time, picked from these tabs. --}}
            <div class="plan-tabs only-mobile" id="plan-tabs" role="tablist">
                @foreach ($plans as $planCard)
                    <button type="button" class="plan-tab" role="tab" :class="{ active: planTab === @js($planCard['key']) }"
                            :aria-selected="planTab === @js($planCard['key'])" x-on:click="planTab = @js($planCard['key'])">
                        <span class="t-name">{{ $planCard['name'] }}</span>
                        <span class="t-price">{{ Str::before($planCard['price'], ' /') }}</span>
                    </button>
                @endforeach
            </div>
            <div class="plans-grid">
                @foreach ($plans as $planCard)
                    <article class="plan-card tone-{{ $planCard['tone'] }}" id="plan-{{ $planCard['key'] }}"
                             :class="{ 'is-active': planTab === @js($planCard['key']) }">
                        <div class="top">
                            <div class="meta"><span>{{ $planCard['index'] }}</span><span>{{ __('dashboard.plan') }}</span></div>
                            <hr>
                            <div>
                                <div class="name">{{ $planCard['name'] }}</div>
                                <div class="price">{{ $planCard['price'] }}</div>
                            </div>
                            <p class="tagline">{{ $planCard['tagline'] }}</p>
                            <div class="tryons">
                                <span>{{ __('dashboard.tryons_label') }}</span>
                                <p>{{ $planCard['tryons'] }}</p>
                            </div>
                        </div>
                        <div class="bottom">
                            <hr>
                            <a class="go" href="{{ route('membership.pricing') }}">
                                <span>{{ $planCard['cta'] }}</span>
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2.5 8h11M9 3.5L13.5 8 9 12.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>
            {{-- Same honesty rule as /pricing: prices without this read as an offer. --}}
            <p class="plans-note" id="plans-note">{{ __('dashboard.plans_note') }}</p>
        </section>
    </div>
@endsection

@section('scripts')
    <script>
        // The category chips keep to one row on desktop: the ones that would
        // wrap hide, and "Ще" lists them. Phones scroll the row instead.
        function chipRow() {
            return {
                more: [],
                moreOpen: false,
                width: 0,
                init() {
                    new ResizeObserver(() => {
                        if (this.$el.clientWidth === this.width) return;
                        this.width = this.$el.clientWidth;
                        this.fit();
                    }).observe(this.$el);
                },
                fit() {
                    const chips = [...this.$el.querySelectorAll('[data-category]')];
                    const more = this.$refs.more;
                    chips.forEach(c => { c.hidden = false; });
                    more.hidden = true;
                    this.more = [];
                    if (! chips.length || window.matchMedia('(max-width: 833px)').matches) return;
                    const top = this.$el.firstElementChild.offsetTop;
                    const wraps = (el) => ! el.hidden && el.offsetTop > top;
                    if (! chips.some(wraps)) return;
                    more.hidden = false;
                    for (let i = chips.length - 1; i >= 0 && (chips.some(wraps) || wraps(more)); i--) {
                        chips[i].hidden = true;
                    }
                    this.more = chips.filter(c => c.hidden).map(c => c.dataset.category);
                },
            };
        }

        // Phone result: once the before/after reaches the top bar it stays
        // there and shrinks with the scroll, to half its size over one frame
        // height of scrolling, and what follows rises right under it (the box
        // holds the shift plus the shrunk frame). Past that, all of it scrolls.
        function shrinkFrame() {
            return {
                init() {
                    const box = this.$el;
                    const mark = box.previousElementSibling;
                    const frame = box.firstElementChild;
                    const grid = box.closest('.tryon-grid');
                    const phone = window.matchMedia('(max-width: 833px)');
                    let queued = false;
                    const update = () => {
                        queued = false;
                        if (! phone.matches || ! grid.classList.contains('has-result')) {
                            ['--shrink-y', '--shrink-s', '--shrink-h'].forEach((name) => box.style.removeProperty(name));
                            return;
                        }
                        const top = document.getElementById('account-topbar')?.offsetHeight ?? 0;
                        const height = frame.offsetHeight;
                        const shift = Math.min(Math.max(top - mark.getBoundingClientRect().top, 0), height);
                        const scale = 1 - shift / height / 2;
                        box.style.setProperty('--shrink-y', shift.toFixed(1) + 'px');
                        box.style.setProperty('--shrink-s', scale.toFixed(4));
                        box.style.setProperty('--shrink-h', (shift + height * scale).toFixed(1) + 'px');
                    };
                    const queue = () => {
                        if (queued) return;
                        queued = true;
                        requestAnimationFrame(update);
                    };
                    window.addEventListener('scroll', queue, { passive: true });
                    window.addEventListener('resize', queue);
                    phone.addEventListener('change', queue);
                    new MutationObserver(queue).observe(grid, { attributes: true, attributeFilter: ['class'] });
                    queue();
                },
            };
        }

        function dashboard() {
            return {
                // Below 834px the page is the phone layout (Figma 1872:8187):
                // shorter wording in the fields and on the card buttons.
                phone: window.matchMedia('(max-width: 833px)').matches,
                // Catalog filters (client-side over the rendered cards).
                query: '',
                // Brand → number of verified pieces; the picker lists these.
                brandCounts: @json($products->countBy('brand_name')->sortKeys()),
                brands: [],
                brandOpen: false,
                brandQuery: '',
                // Active category chip ('' = all).
                category: '',
                // "Мої кольори": only the pieces that suit the member's season.
                mine: @js(request()->boolean('mine') && $myColourIds !== []),
                myColours: @js($myColourIds),
                // A piece picked on the colour page ("Приміряти"), or none.
                selected: @js($picked),
                // The detail modal: every piece's description, photos and
                // pairings, keyed by id; `detail` is the one on screen.
                details: @json($productDetails),
                detail: null,
                detailImage: 0,
                // Hearted pieces, from the account: the wardrobe's "Обране" is
                // the same list.
                liked: @json($likedIds),

                // Try-on state: the same pipeline as the internal page. The
                // photo goes up with the consents (photo_key + consent_id come
                // back), /generate is asked with the member's lead and token,
                // and the signed status URL is polled until done or failed.
                memberLead: @json($memberLead),
                routes: @json($tryonRoutes),
                consentVersion: @json($consentVersion),
                termsVersion: @json($termsVersion),
                photoUrl: null,
                photoFile: null,
                photoKey: null,
                consentId: null,
                // With consent switched off (config/consent.php) there are no
                // boxes, so nothing stands between the photo and the button.
                consent: @json(! config('consent.required')),
                terms: @json(! config('consent.required')),
                dragOver: false,
                // idle | uploading | processing | done | failed
                genState: 'idle',
                genMessage: '',
                // The base pipeline until the first poll sends this generation's own.
                loaderSteps: @js(array_map(fn ($step) => __($step), array_values([...config('fitting_room.loader.steps'), config('fitting_room.loader.final_step')]))),
                loaderIndex: 0,
                loaderTimer: null,
                statusUrl: null,
                beforeUrl: null,
                result: null,
                // The finished look's generation and the piece it was made with:
                // what "save" and "buy" act on, even if the pick moves on.
                generationId: null,
                resultProduct: null,
                shareUrls: {},
                // This photo's finished looks, newest last (Figma 1873:7711):
                // kept in the tab while the photo stays, so two results on the
                // same body can share the before/after frame.
                looks: [],
                MAX_LOOKS: 3,
                // The two generation ids on the frame while comparing, left then right.
                comparePair: [],
                comparing: false,
                trayOpen: false,
                pollAttempts: 0,
                POLL_INTERVAL: 3000,
                // ~30 s per middle step: a look takes about a minute on prod.
                LOADER_STEP_TICKS: 10,
                POLL_MAX: 40,
                paywall: false,
                split: 50,
                // Zoom on the finished look (desktop zoom bar).
                zoom: 1,
                ZOOM_MIN: 1,
                ZOOM_MAX: 2,
                ZOOM_STEP: 0.25,

                // Plan card shown on the mobile tab switcher.
                planTab: @json($plan ?? 'shopaholic'),

                copied: false,
                note: null,

                pickPhoto(event) {
                    this.usePhoto(event.target.files && event.target.files[0]);
                },
                dropPhoto(event) {
                    this.dragOver = false;
                    this.usePhoto(event.dataTransfer && event.dataTransfer.files[0]);
                },
                usePhoto(file) {
                    if (! file || ! ['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return;
                    if (file.size > 12 * 1024 * 1024) {
                        this.genState = 'failed';
                        this.genMessage = @jsText(__('dashboard.js.too_big'));
                        return;
                    }
                    if (this.photoUrl) URL.revokeObjectURL(this.photoUrl);
                    this.photoUrl = URL.createObjectURL(file);
                    // A new photo is a new upload: the old key belongs to the old file.
                    this.photoFile = file;
                    this.photoKey = null;
                    this.consentId = null;
                    // Another body: the old looks no longer compare with the new ones.
                    this.looks = [];
                    this.comparePair = [];
                    this.trayOpen = false;
                    this.resetResult();
                },
                resetResult() {
                    this.stopLoader();
                    this.zoom = 1;
                    this.comparing = false;
                    this.result = null;
                    this.generationId = null;
                    this.resultProduct = null;
                    this.beforeUrl = null;
                    this.shareUrls = {};
                    this.statusUrl = null;
                    this.genState = 'idle';
                    this.genMessage = '';
                },

                // ---- the try-on ----
                get busy() {
                    return this.genState === 'uploading' || this.genState === 'processing';
                },
                // The step in progress. The photo check is the first step and is
                // over once /generate has answered; past it, one step per
                // LOADER_STEP_TICKS polls, and the last one stays open until
                // the look arrives.
                get loaderCurrent() {
                    if (this.genState === 'uploading') return 0;
                    const step = 1 + Math.floor(this.loaderIndex / this.LOADER_STEP_TICKS);
                    return Math.min(step, this.loaderSteps.length - 1);
                },
                get hint() {
                    if (this.busy) return '';
                    if (! this.memberLead) return @jsText(__('dashboard.js.hint_login'));
                    if (! this.hasPhoto()) return @jsText(__('dashboard.js.hint_photo'));
                    if (! this.selected) return @jsText(__('dashboard.js.hint_piece'));
                    if (! this.consent || ! this.terms) return @jsText(__('dashboard.js.hint_consent'));
                    return '';
                },
                // A photo to dress: a picked file, or one already uploaded (the
                // local ?debug_result look arrives with its key and no file).
                hasPhoto() {
                    return !! (this.photoFile || this.photoKey);
                },
                // `pick` lets the phone's product card ask before it picks.
                canGenerate(pick = this.selected) {
                    return !! (this.memberLead && this.hasPhoto() && pick && this.consent && this.terms && ! this.busy);
                },
                async uploadPhoto() {
                    const body = new FormData();
                    body.append('photo', this.photoFile);
                    body.append('consent_accepted', '1');
                    body.append('consent_version', this.consentVersion);
                    body.append('terms_accepted', '1');
                    body.append('terms_version', this.termsVersion);
                    const response = await fetch(this.routes.photo, { method: 'POST', body, headers: { 'Accept': 'application/json' } });
                    const data = await response.json().catch(() => ({}));
                    if (! response.ok) {
                        const errors = data.errors ? Object.values(data.errors).flat() : [];
                        throw new Error(errors[0] ?? data.message ?? @jsText(__('dashboard.js.upload_failed')));
                    }
                    this.photoKey = data.photo_key;
                    this.consentId = data.consent_id;
                },
                async generate() {
                    if (! this.canGenerate()) return;
                    this.resetResult();
                    this.genState = 'uploading';
                    try {
                        if (! this.photoKey) await this.uploadPhoto();
                        this.genState = 'processing';
                        const response = await fetch(this.routes.generate, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify({
                                lead_id: this.memberLead.id,
                                lead_token: this.memberLead.token,
                                product_id: this.selected,
                                photo_key: this.photoKey,
                                consent_id: this.consentId,
                            }),
                        });
                        const data = await response.json().catch(() => ({}));
                        if (response.status === 402 || data.code === 'credits_exhausted') {
                            this.genState = 'idle';
                            this.paywall = true;
                            return;
                        }
                        if (data.code === 'signup_required') {
                            window.location.href = this.routes.login;
                            return;
                        }
                        if (! response.ok) {
                            const errors = data.errors ? Object.values(data.errors).flat() : [];
                            this.fail(data.message ?? errors[0] ?? @jsText(__('dashboard.js.start_failed')));
                            return;
                        }
                        this.statusUrl = data.status_url;
                        this.generationId = data.generation_id;
                        this.resultProduct = this.selected;
                        this.beforeUrl = data.before_url ?? null;
                        this.spendCredit();
                        this.startLoader();
                        this.pollAttempts = 0;
                        this.pollStatus();
                    } catch (error) {
                        this.fail(error.message || @jsText(__('dashboard.js.offline')));
                    }
                },
                async pollStatus() {
                    if (this.genState !== 'processing') return;
                    if (this.pollAttempts++ >= this.POLL_MAX) {
                        this.fail(@jsText(__('dashboard.js.slow')));
                        return;
                    }
                    try {
                        const response = await fetch(this.statusUrl, { headers: { 'Accept': 'application/json' } });
                        const data = await response.json();
                        if (Array.isArray(data.loader_steps) && data.loader_steps.length) this.loaderSteps = data.loader_steps;
                        if (data.status === 'done') {
                            this.stopLoader();
                            this.result = (data.result_urls && data.result_urls[0]) ? data.result_urls[0].url : null;
                            this.shareUrls = data.share || {};
                            this.genState = this.result ? 'done' : 'failed';
                            if (this.result) this.addLook({ id: this.generationId, url: this.result, product: this.resultProduct });
                            if (! this.result) this.genMessage = @jsText(__('generation.failed'));
                            return;
                        }
                        if (data.status === 'failed') {
                            this.fail(data.message || @jsText(__('generation.failed')));
                            return;
                        }
                    } catch (error) { /* try again on the next tick */ }
                    setTimeout(() => this.pollStatus(), this.POLL_INTERVAL);
                },
                // The finished look's piece, as the detail map describes it.
                chosen() {
                    return this.resultProduct ? (this.details[this.resultProduct] ?? null) : null;
                },
                downloadUrl() {
                    return this.generationId ? this.routes.download.replace('/looks/0/', `/looks/${this.generationId}/`) : null;
                },
                shopUrl() {
                    return this.resultProduct ? this.routes.shop.replace('/shop/0', `/shop/${this.resultProduct}`) : null;
                },
                canRegenerate() {
                    return !! (this.resultProduct && this.memberLead && this.hasPhoto() && this.consent && this.terms && ! this.busy);
                },
                // The look on screen once more: its piece back in the pick, then
                // the ordinary try-on (a new generation, a new credit).
                regenerate() {
                    if (! this.canRegenerate()) return;
                    this.selected = this.resultProduct;
                    this.generate();
                },
                // Up to four pieces the catalog pairs with the look's piece.
                resultPairs() {
                    const piece = this.resultProduct ? this.details[this.resultProduct] : null;
                    return piece ? piece.pairs.map((id) => this.details[id]).filter(Boolean).slice(0, 4) : [];
                },
                zoomBy(step) {
                    this.zoom = Math.min(this.ZOOM_MAX, Math.max(this.ZOOM_MIN, this.zoom + step));
                },
                toggleFullscreen(frame) {
                    if (document.fullscreenElement) {
                        document.exitFullscreen();
                    } else {
                        frame?.requestFullscreen?.();
                    }
                },
                // Keep the photo, drop the look and the pick, back to the catalog.
                tryAnother() {
                    this.resetResult();
                    this.selected = null;
                    this.scrollTo('catalog-panel');
                },
                // ---- comparing this photo's looks ----
                addLook(look) {
                    this.looks = [...this.looks.filter((l) => l.id !== look.id), look].slice(-this.MAX_LOOKS);
                    this.comparePair = this.comparePair.filter((id) => this.lookById(id));
                },
                lookById(id) {
                    return this.looks.find((l) => l.id === id) ?? null;
                },
                compareLook(side) {
                    return this.lookById(this.comparePair[side]);
                },
                isPicked(id) {
                    return this.comparePair.includes(id);
                },
                // A tap puts the look on the right; the one there moves left.
                pickLook(id) {
                    if (this.isPicked(id)) return;
                    this.comparePair = [this.comparePair[this.comparePair.length - 1], id].filter(Boolean);
                },
                removeLook(id) {
                    this.looks = this.looks.filter((l) => l.id !== id);
                    this.comparePair = this.comparePair.filter((pid) => pid !== id);
                    if (this.looks.length < 2) this.comparing = false;
                    else if (this.comparing && this.comparePair.length < 2) this.fillPair();
                },
                fillPair() {
                    const rest = this.looks.map((l) => l.id).filter((id) => ! this.isPicked(id));
                    while (this.comparePair.length < 2 && rest.length) this.comparePair.unshift(rest.pop());
                },
                // The phone opens its tray; a desktop has no tray, so with one
                // look it takes the member to the compare strip and its "add a
                // look", and with two it shows the pair on the frame.
                toggleCompare() {
                    if (this.comparing) { this.comparing = false; return; }
                    if (this.looks.length < 2) {
                        this.trayOpen = true;
                        if (! this.phone) {
                            this.scrollTo('compare-strip');
                            document.getElementById('add-look')?.focus({ preventScroll: true });
                        }
                        return;
                    }
                    this.fillPair();
                    this.split = 50;
                    this.comparing = true;
                    if (! this.phone) this.scrollTo('result-panel');
                },
                // The caption names the piece on the frame's right half.
                captionPiece() {
                    const id = this.comparing ? this.compareLook(1)?.product : this.resultProduct;
                    return id ? (this.details[id] ?? null) : null;
                },
                fail(message) {
                    this.stopLoader();
                    this.genState = 'failed';
                    this.genMessage = message;
                },
                startLoader() {
                    this.loaderIndex = 0;
                    this.loaderTimer = setInterval(() => { this.loaderIndex++; }, this.POLL_INTERVAL);
                },
                stopLoader() {
                    if (this.loaderTimer) { clearInterval(this.loaderTimer); this.loaderTimer = null; }
                },
                // One credit per look; the top bar's credit chip follows without a reload.
                spendCredit() {
                    document.querySelectorAll('#token-balance').forEach((el) => {
                        const left = Math.max(0, parseInt(el.textContent, 10) - 1);
                        if (! Number.isNaN(left)) el.textContent = String(left);
                    });
                },
                toggle(id) {
                    this.selected = this.selected === id ? null : id;
                },
                // ---- product detail modal ----
                openDetail(id) {
                    const product = this.details[id];
                    if (! product) return;
                    this.detail = product;
                    this.detailImage = 0;
                    document.documentElement.style.overflow = 'hidden';
                    this.$nextTick(() => {
                        this.$refs.pmTrack?.scrollTo({ left: 0 });
                        (this.phone ? this.$refs.detailBack : this.$refs.detailClose)?.focus();
                    });
                },
                showDetailImage(i) {
                    this.detailImage = i;
                    const track = this.$refs.pmTrack;
                    track?.scrollTo({ left: i * track.clientWidth, behavior: 'smooth' });
                },
                closeDetail() {
                    if (! this.detail) return;
                    this.detail = null;
                    document.documentElement.style.overflow = '';
                },
                detailPairs() {
                    return this.detail ? this.detail.pairs.map(id => this.details[id]).filter(Boolean) : [];
                },
                pickFromDetail() {
                    this.selected = this.detail.id;
                    this.closeDetail();
                },
                generateFromDetail() {
                    const go = this.canGenerate(this.detail.id);
                    this.pickFromDetail();
                    if (go) this.generate();
                },
                isLiked(id) {
                    return this.liked.includes(id);
                },
                // Optimistic: the heart flips at once and flips back if the
                // server says no.
                async toggleLike(id) {
                    if (! id) return;
                    const liking = ! this.isLiked(id);
                    this.liked = liking ? [...this.liked, id] : this.liked.filter(x => x !== id);
                    if (! await this.saveLike(id, liking)) {
                        this.liked = liking ? this.liked.filter(x => x !== id) : [...this.liked, id];
                    }
                },
                async saveLike(id, liking) {
                    try {
                        const response = await fetch(this.routes.favorite.replace(/\/0$/, `/${id}`), {
                            method: liking ? 'POST' : 'DELETE',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                        });
                        return response.ok;
                    } catch (e) {
                        return false;
                    }
                },
                // Hearts used to live in this browser only: hand any left
                // there to the account once, then forget them.
                init() {
                    window.matchMedia('(max-width: 833px)').addEventListener('change', (e) => { this.phone = e.matches; });
                    let local = [];
                    try { local = JSON.parse(localStorage.getItem('account.liked') || '[]'); } catch (e) { return; }
                    const known = new Set(Object.keys(this.details).map(Number));
                    local.filter(id => known.has(id) && ! this.isLiked(id)).forEach(async (id) => {
                        if (await this.saveLike(id, true)) this.liked = [...this.liked, id];
                    });
                    try { localStorage.removeItem('account.liked'); } catch (e) { /* storage off */ }
                    this.showDebugLook(@json($debugLook));
                },
                // Local only (?debug_result): straight to step 3 with a finished look.
                showDebugLook(look) {
                    if (! look) return;
                    this.photoUrl = look.before;
                    this.beforeUrl = look.before;
                    this.result = look.after;
                    this.generationId = look.id;
                    this.resultProduct = look.product;
                    this.selected = look.product;
                    this.photoKey = look.photo_key;
                    this.genState = 'done';
                    this.addLook({ id: look.id, url: look.after, product: look.product });
                    this.$nextTick(() => this.scrollTo('result-panel'));
                },
                matches(brand, name, category, id) {
                    if (this.mine && ! this.myColours.includes(id)) return false;
                    if (this.category && category !== this.category) return false;
                    if (this.brands.length && ! this.brands.includes(brand)) return false;
                    const q = this.query.trim().toLowerCase();
                    return q === ''
                        || brand.toLowerCase().includes(q)
                        || name.toLowerCase().includes(q)
                        || category.toLowerCase().includes(q);
                },

                // ---- brand picker ----
                toggleBrands() {
                    this.brandOpen = ! this.brandOpen;
                    this.brandQuery = '';
                    if (this.brandOpen) {
                        this.$nextTick(() => this.$refs.brandSearch?.focus());
                    }
                },
                brandOptions() {
                    const q = this.brandQuery.trim().toLowerCase();
                    return Object.keys(this.brandCounts).filter(b => q === '' || b.toLowerCase().includes(q));
                },
                toggleBrand(brand) {
                    this.brands = this.brands.includes(brand)
                        ? this.brands.filter(b => b !== brand)
                        : [...this.brands, brand];
                },
                scrollTo(id) {
                    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                },
                async share() {
                    const url = window.location.href;
                    if (navigator.share) {
                        try { await navigator.share({ url }); return; } catch (e) { /* dismissed */ }
                    }
                    try { await navigator.clipboard.writeText(url); } catch (e) { return; }
                    this.copied = true;
                    setTimeout(() => { this.copied = false; }, 2400);
                },
                // The look's own link: the branded feed card when composed,
                // the bare result otherwise. Both are short-lived signed URLs.
                lookLink() {
                    return this.shareUrls.feed ?? this.result;
                },
                async shareLook() {
                    const url = this.lookLink();
                    if (! url) return;
                    if (navigator.share) {
                        try { await navigator.share({ url }); return; } catch (e) { /* dismissed */ }
                    }
                    await this.copyLookLink();
                },
                async copyLookLink() {
                    const url = this.lookLink();
                    if (! url) return;
                    try { await navigator.clipboard.writeText(url); } catch (e) { return; }
                    this.copied = true;
                    setTimeout(() => { this.copied = false; }, 2400);
                },
                comingSoon(which) {
                    this.note = which;
                },
            };
        }
    </script>
@endsection

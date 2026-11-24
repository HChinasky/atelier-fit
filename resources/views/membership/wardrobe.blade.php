@extends('membership.shell')

@section('title', __('wardrobe.title'))

{{-- "Мій гардероб": head with the wardrobe / favourites tabs, category
     chips, then a card per finished try-on (frame 1482:27566). "Переглянути"
     opens that one set (frame 1456:10104) — the before/after look with its "saved" note on the
     left, the pieces it was made of on the right, each with a shop button
     that reads brand and link from the product row.

     Favourites are the account's own list (the dashboard's hearts, stored
     server-side). "Remove this set" and the per-piece close are kept too:
     they hide the set / piece for good (WardrobeController), they do not
     erase the generation. --}}

@section('styles')
        .wardrobe-tabs .dl-btn { padding: 12px 23px; min-height: 54px; }
        .wardrobe-tabs .dl-btn svg { flex: none; }

        /* Category chips: the pill style of the plan switcher, as drawn */
        .cat-chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 30px 0 22px; }
        .cat-chip { padding: 4px 11px; border: 1px solid rgba(45, 45, 45, .2); border-radius: 0; background: none;
            cursor: pointer; font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 9.5px; letter-spacing: .08em;
            text-transform: uppercase; color: #6B6B6B; white-space: nowrap; transition: background .25s ease, color .25s ease; }
        .cat-chip:hover { border-color: var(--ink-2); color: var(--ink-2); }
        .cat-chip.active { background: var(--ink-2); border-color: var(--ink-2); color: var(--surface); }

        /* Cards: one per finished try-on (frame 1482:27566), opens the set */
        .look-cards { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 30px 9px; }
        .look-card { display: flex; flex-direction: column; gap: 12px; }
        .look-card .pic { position: relative; aspect-ratio: 244 / 327; overflow: hidden; background: var(--surface-2);
            border: none; padding: 0; cursor: pointer; }
        .look-card .pic img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; }
        .look-card .pic .placeholder { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            font-size: 9px; letter-spacing: .2em; text-transform: uppercase; color: var(--muted); }

        /* One set = one finished try-on, opened from its card */
        .look-set { margin-bottom: 40px; }
        .look-set-tools { display: flex; align-items: center; justify-content: flex-end; gap: 20px; margin-bottom: 14px; }
        .look-set-tools .back { margin-inline-end: auto; }
        .back { display: inline-flex; align-items: center; gap: 8px; background: none; border: none; padding: 0; cursor: pointer;
            font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 16px; color: var(--muted); transition: color .3s ease; }
        .back:hover { color: var(--ink-2); }
        .look-set-tools .remove-set { background: none; border: none; padding: 0; cursor: pointer;
            font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 16px; color: var(--muted); transition: color .3s ease; }
        .look-set-tools .remove-set:hover { color: var(--ink-2); }
        .icon-circle { width: 52px; height: 52px; border-radius: 50%; border: 1px solid var(--ink-2); background: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center; color: var(--ink-2); padding: 0;
            transition: background .25s ease, color .25s ease; }
        .icon-circle:hover { background: var(--ink-2); color: var(--surface); }
        /* Figma 1954:9485: no frame; the compare and the pieces at 1048 : 512 */
        .look-set-card { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; align-items: start; }
        @media (min-width: 1280px) { .look-set-card { grid-template-columns: minmax(0, 1048fr) minmax(0, 512fr); } }

        .look-visual { position: relative; aspect-ratio: 1048 / 657; overflow: hidden; background: var(--surface-2); }
        .look-visual > img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; }
        .look-visual .placeholder { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            font-size: 9px; letter-spacing: .2em; text-transform: uppercase; color: var(--muted);
            background: repeating-linear-gradient(45deg, #F0F0F0 0 12px, rgba(45, 45, 45, .04) 12px 24px); }
        /* Four tiles under the compare: 144 x 75, 12px corners, ink at 5% */
        .set-tiles { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 24px; }
        .set-tile { width: 144px; min-height: 75px; display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 6px; padding: 9px 4px; border: none; border-radius: 12px; background: rgba(45, 45, 45, .05); cursor: pointer;
            color: var(--ink-2); text-decoration: none; font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 16px; line-height: 1.5;
            transition: background .25s ease; }
        .set-tile img { display: block; height: 24px; width: auto; }
        .set-tile:hover, .set-tile[aria-pressed="true"] { background: rgba(45, 45, 45, .1); }
        .set-tile:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
        .set-copied { margin: 8px 0 0; font-size: 13px; color: var(--muted); }
        .set-note { margin: 12px 0 0; font-size: 12px; line-height: 1.5; color: var(--muted); }
        /* The stylist under the set: F0F0F0, 150px, her picture, the line, the button */
        .set-ask { display: flex; align-items: center; gap: 32px; margin-top: 24px; min-height: 150px; padding: 5px 32px 0 90px;
            background: var(--surface-2); }
        .set-ask-pic { flex: none; align-self: flex-end; width: 120px; height: 145px; object-fit: cover; object-position: 50% 0; transform: scaleX(-1); }
        .set-ask-text { flex: 1; min-width: 0; padding: 16px 0 20px; }
        .set-ask-text h2 { font-size: 38px; line-height: normal; color: var(--ink-2); }
        .set-ask-text p { margin: 15px 0 0; font-size: 22px; line-height: 1.5; color: var(--muted); }
        .set-ask .dl-btn { flex: none; }

        /* Pieces column: scrolls inside the set, thin bar as in the frame */
        .pieces { display: flex; flex-direction: column; gap: 24px; }
        @media (min-width: 1280px) { .pieces { max-height: 975px; overflow-y: auto; padding-inline-end: 14px;
            scrollbar-width: thin; scrollbar-color: #2D2D2D rgba(45, 45, 45, .12); } }
        /* Figma 1954:9485: a muted frame, the 136 x 189 picture beside the text,
           then the shop and "try another" as 54px rows, 10px apart */
        .piece { display: grid; grid-template-columns: 136px minmax(0, 1fr); gap: 14px 21px; padding: 9px;
            border: 1px solid var(--muted); background: var(--surface); }
        .piece .pic { aspect-ratio: 136 / 189; background: var(--surface-2); overflow: hidden; }
        .piece-actions { grid-column: 1 / -1; display: flex; flex-direction: column; gap: 10px; }
        .piece-actions .dl-btn { min-height: 54px; font-size: 16px; }
        .piece .pic img { width: 100%; height: 100%; object-fit: contain; }
        .piece .body { display: flex; flex-direction: column; gap: 8px; padding: 0; min-width: 0; }
        .piece .row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .piece .brand { font-size: 14px; line-height: 1.5; text-transform: uppercase; color: var(--muted); min-width: 0;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .piece .icons { display: flex; gap: 14px; }
        .piece .icon-btn { width: 28px; height: 28px; border: none; background: none; padding: 0; cursor: pointer;
            color: var(--ink-2); display: flex; align-items: center; justify-content: center; }
        .piece .icon-btn svg { width: 28px; height: 28px; }
        .piece .icon-btn.active .heart-fill { fill: var(--ink-2); }
        .piece .name { font-size: 16px; line-height: normal; color: var(--ink-2); }
        .piece .price { margin: 0; font-size: 16px; line-height: 1.5; color: var(--muted); }
        .piece .icons { gap: 8px; }
        .piece .icon-btn, .piece .icon-btn svg { width: 22px; height: 22px; }

        /* "Обране" cards, Figma 1907:7903: 380 x 413 pictures with 14px corners,
           four a row 24px apart; 20px to the text (12 / 16 / 16, 8 apart), 20px
           to the two 56px buttons 14px apart. The rounds are the looks' own. */
        .fav-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr)); gap: 24px; }
        .fav-card { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
        .fav-card .pic { position: relative; aspect-ratio: 380 / 413; border-radius: 14px; background: rgba(45, 45, 45, .04); }
        .fav-card .pic img { position: absolute; inset: 0; display: block; width: 100%; height: 100%; object-fit: contain; border-radius: inherit; }
        .fav-card .pic .card-tools { z-index: 1; }
        :is(.fav-card, .look-card) .text { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
        :is(.fav-card, .look-card) .text > * { margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        :is(.fav-card, .look-card) .brand { font-size: 12px; line-height: 1.5; text-transform: uppercase; color: var(--muted); }
        :is(.fav-card, .look-card) .name { font-family: var(--serif); font-size: 16px; line-height: normal; color: var(--ink-2); }
        :is(.fav-card, .look-card) .price { font-size: 16px; line-height: 1.5; color: var(--muted); }
        :is(.fav-card, .look-card) .actions { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: auto; }
        :is(.fav-card, .look-card) .actions .dl-btn { min-height: 56px; padding: 10px 8px; }

        .wardrobe-empty { margin-top: 30px; }
        .fav-empty { margin: 30px 0; font-size: 14px; color: var(--muted); }

        /* Before / after corner labels (mobile frame) */
        .ba-label { position: absolute; top: 20px; font-size: 16px; line-height: 1.5; font-weight: 400;
            text-transform: uppercase; color: var(--surface); text-shadow: 0 1px 6px rgba(45, 45, 45, .5); }
        .ba-before { left: 20px; }
        .ba-after { right: 20px; }

        /* ---- Every screen: Figma 1906:11262 / 1873:7879 (empty), 1907:7761 /
           1874:8247 (cards), 1917:8226 (picking looks), 1878:7507 (delete).
           Sizes are the 1920px frame's; the phone's follow below. ---- */
        .wardrobe-empty-box { display: flex; flex-direction: column; align-items: center; text-align: center;
            padding: 45px 24px 40px; border: 1px solid rgba(45, 45, 45, .08); background: var(--surface); }
        .wardrobe-empty-box .empty-pic { width: 234px; height: 268px; object-fit: cover; margin-bottom: 30px; }
        .wardrobe-empty-box h2 { max-width: 448px; font-size: 22px; line-height: normal; color: var(--ink-2); }
        .wardrobe-empty-box p { margin: 15px 0 0; font-size: 22px; line-height: 1.5; color: var(--muted); }
        .wardrobe-empty-box .empty-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 16px 24px; margin-top: 30px; }
        .wardrobe-empty-box .dl-btn { min-height: 56px; }

        /* Cards: 378 x 413 at 1920 (four a row), 14px corners, the heart
           and the menu as white rounds top-right. The icons are the frame's own
           (Figma 1917:7887 / 1917:7889): #2D2D2D on a 32px white round, the svg
           as large as the round. */
        .look-card { position: relative; }
        .look-card .pic:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
        .card-tools { position: absolute; top: 14px; inset-inline-end: 14px; display: flex; gap: 12px; }
        .card-tool { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; padding: 0;
            border: none; border-radius: 50%; background: var(--surface); color: var(--ink-2); cursor: pointer; }
        .card-tool svg { display: block; width: 100%; height: 100%; }
        .card-tool.active .heart-fill { fill: currentColor; }
        .card-tool:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
        .card-menu { position: absolute; z-index: 5; top: calc(100% + 6px); inset-inline-end: 0; min-width: 180px; display: flex; flex-direction: column;
            padding: 6px 0; border: 1px solid var(--line-soft); border-radius: 8px; background: var(--surface);
            box-shadow: 0 18px 40px -20px rgba(45, 45, 45, .45); }
        .card-menu > * { padding: 10px 16px; border: none; background: none; text-align: start; cursor: pointer; text-decoration: none;
            font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 15px; line-height: 1.4; color: var(--ink-2); }
        .card-menu > *:hover, .card-menu > *:focus-visible { background: rgba(45, 45, 45, .05); outline: none; }
        .card-menu .danger { color: var(--danger); }
        /* While picking: a ring and a tick on the chosen ones */
        .look-card.is-picking .pic { cursor: pointer; }
        .look-card.is-picked .pic { box-shadow: inset 0 0 0 3px var(--ink-2); }
        .pick-mark { position: absolute; top: 14px; inset-inline-end: 14px; width: 32px; height: 32px; display: flex; align-items: center;
            justify-content: center; border-radius: 50%; border: 1.5px solid var(--ink-2); background: var(--surface); color: transparent; }
        .look-card.is-picked .pick-mark { background: var(--ink-2); color: var(--surface); }

        /* "Оберіть 2–3 образи": F0F0F0 band, 14px corners, the icon tile in DCDBDB (ink at ~17%) */
        .pick-banner { display: flex; flex-wrap: wrap; align-items: center; gap: 16px 32px; margin-bottom: 18px; padding: 8px 24px 8px 8px;
            border-radius: 14px; background: var(--surface-2); }
        .pick-icon { flex: none; width: 125px; height: 109px; display: flex; align-items: center; justify-content: center;
            border-radius: 17px; background: rgba(45, 45, 45, .17); }
        .pick-text { flex: 1; min-width: 240px; }
        .pick-text h2 { font-size: 24px; line-height: 41.6px; color: var(--ink-2); }
        .pick-text p { margin: 8px 0 0; font-size: 18px; line-height: 1.5; color: var(--muted); }
        .pick-count { margin: 0 8px 0 0; font-size: 32px; line-height: 41.6px; color: var(--ink-2); }
        .pick-count b { font-weight: 500; }
        .pick-banner .dl-btn { flex: none; }

        /* Side by side */
        .compare-view-head { display: flex; align-items: center; gap: 24px; margin: 30px 0 24px; }
        .compare-view-head h2 { flex: 1; font-size: 24px; color: var(--ink-2); }
        .compare-grid { display: grid; grid-template-columns: repeat(var(--n, 2), minmax(0, 520px)); gap: 24px; }
        .compare-col { display: flex; flex-direction: column; gap: 12px; min-width: 0; }
        .compare-pic { aspect-ratio: 3 / 4; border-radius: 14px; overflow: hidden; background: rgba(45, 45, 45, .04); }
        .compare-pic img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .compare-piece { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 0;
            border-bottom: 1px solid var(--line-soft); }
        .compare-piece .text { min-width: 0; }
        .compare-piece p { margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .compare-piece .brand { font-size: 12px; line-height: 1.5; text-transform: uppercase; color: var(--muted); }
        .compare-piece .name { font-family: var(--serif); font-size: 16px; color: var(--ink-2); }
        .compare-piece .price { font-size: 15px; line-height: 1.5; color: var(--muted); }
        .compare-piece .dl-btn { flex: none; padding: 10px 18px; font-size: 14px; }

        /* "Видалити образ": the danger red at low alpha behind the bin, as drawn */
        .confirm-scrim { position: fixed; inset: 0; z-index: 70; display: flex; align-items: center; justify-content: center;
            padding: 24px; background: rgba(45, 45, 45, .45); }
        .confirm-card { width: 100%; max-width: 360px; padding: 24px; border-radius: 14px; background: var(--surface); text-align: center;
            font-family: 'Montserrat', sans-serif; font-weight: 300; animation: npop .25s cubic-bezier(.22, 1, .36, 1); }
        /* Figma 1878:7507: a 42px ring with the bin about half its height. The bin
           sits in the middle of its 28px box, so the svg is set larger than the bin. */
        .confirm-icon { width: 60px; height: 60px; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; background: rgba(166, 67, 46, .18); color: var(--ink-2); }
        /* Teleported out of .dl, so the heading sets its own face. */
        .confirm-card h2 { margin: 0; font-family: 'Prata', 'Playfair Display', serif; font-weight: 400; font-size: 22px; line-height: 1.3; color: var(--ink-2); }
        .confirm-card p { margin: 8px 0 0; font-size: 14px; line-height: 1.5; color: var(--muted); }
        .confirm-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 24px; }
        .confirm-actions .dl-btn { min-height: 46px; padding: 10px; font-size: 13px; }

        @media (min-width: 834px) {
            .wardrobe-tabs .dl-btn { min-height: 58px; }
            /* Figma 1907:7761: 15px from the lede to the bar, 24px from it to the cards */
            .wardrobe-bar { display: flex; align-items: center; justify-content: space-between; gap: 16px 24px; margin: 15px 0 24px; }
            .wardrobe-bar .cat-chips { margin: 0; gap: 8px; }
            .cat-chip { padding: 8px 12px; border-color: var(--line-soft); border-radius: 4px; font-size: 16px; line-height: 1.5;
                letter-spacing: 0; text-transform: none; color: var(--ink-2); }
            .compare-looks { flex: none; gap: 10px; min-height: 56px; }
            .look-cards { grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px; }
            /* Figma 1907:7903: no frame, 20px between picture, text and buttons */
            .look-cards .look-card { gap: 20px; border: none; overflow: visible; }
            .look-card .pic { aspect-ratio: 378 / 413; border-radius: 14px; background: rgba(45, 45, 45, .04); }
        }
        /* Figma 1907:7761: the tabs stand level with the heading's foot, the lede
           runs under both. The head's inner block dissolves into the grid, so the
           phone keeps its own order (title, lede, tabs). */
        @media (min-width: 1280px) {
            #wardrobe-section > .dl-head { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 0 24px; align-items: end; }
            #wardrobe-section > .dl-head > div:first-child { display: contents; }
            #wardrobe-section > .dl-head .eyebrow { grid-column: 1; grid-row: 1; }
            #wardrobe-section > .dl-head h1 { grid-column: 1; grid-row: 2; }
            #wardrobe-section > .dl-head .wardrobe-tabs { grid-column: 2; grid-row: 1 / span 2; }
            #wardrobe-section > .dl-head .lede { grid-column: 1 / -1; grid-row: 3; margin-top: 15px; }
        }
        /* Narrow desktops and tablets: the band wraps under its text. */
        @media (min-width: 834px) and (max-width: 1399px) {
            .wardrobe-bar { flex-wrap: wrap; }
            .pick-icon { width: 90px; height: 90px; }
            .pick-icon img { width: 44px; height: 44px; }
            .pick-text h2 { font-size: 20px; line-height: 1.3; }
            .pick-text p { font-size: 15px; }
            .pick-count { font-size: 24px; }
        }

        /* ---- Phone, Figma 1874:8247 / 1873:7879 / 1878:7507 (375px frames):
           16px title, 32px buttons with 9px tracked labels, a sideways chip
           row with its line, 161 x 176 cards with 18px rounds. ---- */
        @media (max-width: 833px) {
            .dl-head .eyebrow { display: none; }
            .dl-head h1 { font-size: 16px; line-height: 21px; }
            .dl-head .lede { margin-top: 4px; font-size: 12px; line-height: 1.5; }
            .wardrobe-tabs { gap: 16px; }
            .wardrobe-tabs .dl-btn { min-height: 32px; padding: 6px 8px; border-radius: 4px; font-size: 9px; letter-spacing: .13em; gap: 8px; }
            .wardrobe-tabs .dl-btn-outline { border-color: rgba(45, 45, 45, .6); }
            .wardrobe-tabs .dl-btn svg { width: 14px; height: 14px; }
            .cat-chips { flex-wrap: nowrap; gap: 6px; margin: 12px 0 0; overflow-x: auto; scrollbar-width: none; }
            .cat-chips::-webkit-scrollbar { display: none; }
            .cat-chip { flex: none; padding: 4px 6px; border-color: var(--line-soft); border-radius: 4px; font-size: 12px; line-height: 1.4;
                letter-spacing: 0; text-transform: none; color: var(--ink-2); }
            .chip-meter { display: block; height: 2px; margin: 4px 0 12px; background: rgba(45, 45, 45, .04); }
            .chip-meter span { display: block; height: 100%; background: var(--ink-2); transition: margin-inline-start .1s linear; }
            .compare-looks { width: 100%; min-height: 32px; margin-bottom: 12px; padding: 6px; border-color: rgba(45, 45, 45, .6);
                border-radius: 4px; font-size: 9px; letter-spacing: .13em; gap: 8px; }
            .compare-looks img { width: 14px; height: 14px; }

            .look-cards { gap: 12px 13px; }
            /* The looks are 3:4: a 3:4 box shows them whole and edge to edge,
               no bars; the card keeps its frame with 6px inside it. */
            .look-cards .look-card { padding: 6px; border-radius: 8px; }
            .look-card .pic { aspect-ratio: 3 / 4; border-radius: 6px; background: transparent; }
            .card-tools { top: 6px; inset-inline-end: 9px; gap: 6px; }
            /* "Обране" on a phone: two 161px cards a row, as the phone's other cards */
            .fav-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px 13px; }
            .fav-card, .look-cards .look-card { gap: 6px; }
            .fav-card .pic { aspect-ratio: 3 / 4; border-radius: 6px; background: transparent; }
            :is(.fav-card, .look-card) .text { gap: 4px; }
            :is(.fav-card, .look-card) .brand { font-size: 9px; }
            :is(.fav-card, .look-card) :is(.name, .price) { font-size: 12px; }
            :is(.fav-card, .look-card) .actions { gap: 4px; }
            :is(.fav-card, .look-card) .actions .dl-btn { min-height: 22px; padding: 4px 2px; border-radius: 4px; font-size: 9px; letter-spacing: .13em; line-height: 1; }
            :is(.fav-card, .look-card) .actions .dl-btn-outline { border-color: rgba(45, 45, 45, .6); }
            .card-tool { width: 18px; height: 18px; }
            .card-menu { min-width: 150px; }
            .card-menu > * { padding: 8px 12px; font-size: 13px; }
            .pick-mark { top: 6px; inset-inline-end: 9px; width: 20px; height: 20px; }
            .pick-mark svg { width: 10px; height: 10px; }
            .look-card.is-picked .pic { box-shadow: inset 0 0 0 2px var(--ink-2); }

            .pick-banner { gap: 10px 12px; margin-bottom: 12px; padding: 8px; border-radius: 8px; }
            .pick-icon { width: 56px; height: 56px; border-radius: 8px; }
            .pick-icon img { width: 28px; height: 28px; }
            /* The copy takes the row beside the icon; count and buttons go under it */
            .pick-text { flex: 1 1 calc(100% - 68px); min-width: 0; }
            .pick-text h2 { font-size: 14px; line-height: 1.3; }
            .pick-text p { margin-top: 4px; font-size: 11px; }
            .pick-count { margin: 0; font-size: 18px; line-height: 1; }
            .pick-banner .dl-btn { flex: 1; min-height: 32px; padding: 6px; border-radius: 4px; font-size: 9px; letter-spacing: .13em; }
            .compare-view-head { gap: 12px; margin: 12px 0; }
            .compare-view-head h2 { font-size: 16px; }
            .compare-view-head .dl-btn { min-height: 32px; padding: 6px 12px; font-size: 9px; }
            .compare-grid { grid-template-columns: repeat(var(--n, 2), minmax(0, 1fr)); gap: 8px; }
            .compare-pic { border-radius: 6px; }
            .compare-piece { flex-direction: column; align-items: stretch; gap: 6px; padding: 8px 0; }
            .compare-piece .brand { font-size: 9px; }
            .compare-piece .name { font-size: 12px; }
            .compare-piece .price { font-size: 12px; }
            .compare-piece .dl-btn { min-height: 28px; padding: 4px; border-radius: 4px; font-size: 9px; }

            .wardrobe-empty-box { margin-top: 24px; padding: 0; border: none; }
            .wardrobe-empty-box .empty-pic { width: 192px; height: 240px; margin-bottom: 20px; }
            .wardrobe-empty-box h2 { max-width: 280px; font-size: 18px; line-height: 1.35; }
            .wardrobe-empty-box p { margin-top: 8px; font-size: 12px; }
            .wardrobe-empty-box .empty-actions { flex-direction: column; align-self: stretch; gap: 12px; margin-top: 36px; }
            .wardrobe-empty-box .dl-btn { min-height: 32px; padding: 6px; border-radius: 4px; font-size: 9px; letter-spacing: .13em; gap: 8px; }
            .wardrobe-empty-box .dl-btn-outline { border-color: rgba(45, 45, 45, .6); }
            .wardrobe-empty-box .dl-btn svg { width: 14px; height: 14px; }

            .confirm-card { max-width: 250px; padding: 20px; }
            .confirm-icon { width: 42px; height: 42px; }
            .confirm-icon svg { width: 32px; height: 32px; }
            .confirm-card h2 { font-size: 16px; }
            .confirm-card p { margin-top: 4px; font-size: 12px; }
            .confirm-actions { gap: 6px; margin-top: 20px; }
            .confirm-actions .dl-btn { min-height: 32px; padding: 6px; border-radius: 4px; font-size: 9px; letter-spacing: .13em; }
        }

        /* ---- An opened set on a phone, Figma 1954:9893 (375px): the page
           head stays, the tabs and chips step aside; a 335 x 236 before /
           after, four action tiles, a 335 x 116 card per piece and the
           stylist card, all 8px apart on ink-5% grounds with 8px corners
           (the try-on's own result screen, dashboard m-*). ---- */
        @media (max-width: 833px) {
            .dl-head > div { width: 100%; text-align: center; }
            .dl .lede { margin-inline-start: auto; margin-inline-end: auto; }
            /* Tabs and chips: sized in the phone block of Figma 1874:8247 above. */
            .wardrobe-tabs { width: 100%; display: grid; grid-template-columns: 1fr 1fr; }
            .dl.set-open .wardrobe-tabs, .dl.set-open .wardrobe-bar { display: none; }

            .look-set { margin: 12px 0 24px; }
            .look-set-head { margin-bottom: 8px; }
            .look-set-head .back { font-size: 12px; gap: 4px; }
            .look-set-card { padding: 0; border: none; gap: 0; }
            .look-visual { aspect-ratio: 335 / 236; border-radius: 8px; }
            .look-visual > img, .look-visual .compare img { object-fit: contain; }
            .look-visual .compare .knob { width: 32px; height: 32px; margin: -16px 0 0 -16px; }
            .look-visual .compare .knob svg { width: 12.8px; height: 12.8px; }
            .ba-label { top: 14px; font-size: 14px; }
            .ba-before { left: 14px; }
            .ba-after { right: 14px; }
            .look-set-card > .pieces { display: none; }

            .m-tiles { display: flex; gap: 8px; margin-top: 8px; }
            .m-tile { flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; align-items: center; gap: 3px;
                height: 51px; padding: 6px 2px 0; border: none; border-radius: 8px; background: rgba(45, 45, 45, .05);
                font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 12px; line-height: 1.5; color: var(--ink-2);
                text-decoration: none; cursor: pointer; white-space: nowrap; }
            .m-tile img { display: block; height: 18px; width: auto; }
            .m-tile:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
            .m-copied { margin: 6px 0 0; font-size: 10px; text-align: center; color: var(--muted); }

            .m-btn { display: inline-flex; align-items: center; justify-content: center; height: 32px; padding: 0 8px;
                border-radius: 4px; font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 9px; letter-spacing: 1.17px;
                text-transform: uppercase; white-space: nowrap; text-decoration: none; cursor: pointer; }
            .m-btn-outline { border: 1px solid rgba(45, 45, 45, .6); background: none; color: var(--ink-2); }
            .m-btn-dark { border: 1px solid var(--ink-2); background: var(--ink-2); color: var(--surface); }
            .m-btn:disabled { opacity: .4; cursor: default; }
            .m-btn:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }

            .m-item { position: relative; display: grid; grid-template-columns: 91px minmax(0, 1fr); grid-template-rows: auto 1fr; column-gap: 8px;
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
            .m-item-actions { align-self: end; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 0 4px 4px 0; }

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
@endsection

@section('section')
    <div class="section-rise dl" id="wardrobe-section" x-data="wardrobe()" :class="{ 'set-open': open !== null }">
        {{-- Head (Figma 1906:11348): eyebrow with the member's name, the
             wardrobe / favourites tabs on the right. --}}
        @php($firstName = \Illuminate\Support\Str::before(trim((string) request()->user()->name), ' '))
        <div class="dl-head">
            <div>
                <div class="eyebrow">{{ $firstName !== '' ? __('wardrobe.eyebrow_named', ['name' => $firstName]) : __('wardrobe.eyebrow') }}</div>
                <h1>{{ __('wardrobe.heading') }}</h1>
                <p class="lede">{{ __('wardrobe.lede') }}</p>
            </div>
            @unless ($locked)
                <div class="dl-actions wardrobe-tabs" id="wardrobe-tabs" role="tablist">
                    <button type="button" class="dl-btn" role="tab" :class="tab === 'wardrobe' ? 'dl-btn-dark' : 'dl-btn-outline'"
                            :aria-selected="tab === 'wardrobe'" x-on:click="tab = 'wardrobe'; close(); cancelPicking()">{{ __('wardrobe.tab_wardrobe') }}</button>
                    <button type="button" class="dl-btn" role="tab" :class="tab === 'favorites' ? 'dl-btn-dark' : 'dl-btn-outline'"
                            :aria-selected="tab === 'favorites'" x-on:click="tab = 'favorites'; close(); cancelPicking()">
                        <svg width="24" height="24" viewBox="5 6 22 22" fill="none" aria-hidden="true"><path class="heart-fill" d="M16 26C15.8619 25.9996 15.7278 25.9535 15.6187 25.869C12.1431 23.175 9.74899 20.8551 8.07372 18.5663C5.93588 15.6415 5.4483 12.9412 6.62349 10.5402C7.46113 8.82525 9.86776 7.42208 12.6807 8.23904C14.0219 8.62552 15.192 9.45436 16 10.5901C16.808 9.45436 17.9781 8.62552 19.3193 8.23904C22.126 7.43455 24.5389 8.82525 25.3765 10.5402C26.5517 12.9412 26.0641 15.6415 23.9263 18.5663C22.251 20.8551 19.8569 23.175 16.3813 25.869C16.2722 25.9535 16.1381 25.9996 16 26Z" fill="none"/><path d="M16 26C15.8619 25.9996 15.7278 25.9535 15.6187 25.869C12.1431 23.175 9.74899 20.8551 8.07372 18.5663C5.93588 15.6415 5.4483 12.9412 6.62349 10.5402C7.46113 8.82525 9.86776 7.42208 12.6807 8.23904C14.0219 8.62552 15.192 9.45436 16 10.5901C16.808 9.45436 17.9781 8.62552 19.3193 8.23904C22.126 7.43455 24.5389 8.82525 25.3765 10.5402C26.5517 12.9412 26.0641 15.6415 23.9263 18.5663C22.251 20.8551 19.8569 23.175 16.3813 25.869C16.2722 25.9535 16.1381 25.9996 16 26ZM11.0805 9.25555C10.4111 9.22956 9.74735 9.38592 9.16036 9.70784C8.57338 10.0298 8.08535 10.5051 7.74867 11.0828C6.77977 13.0659 7.21734 15.2736 9.08639 17.8242C11.0719 20.3809 13.3979 22.6553 16 24.5844C18.6017 22.6572 20.9276 20.3849 22.9136 17.8305C24.7889 15.2736 25.2202 13.0659 24.2513 11.089C23.6262 9.84176 21.7509 8.85019 19.6631 9.4364C18.9936 9.63378 18.373 9.96881 17.8412 10.4199C17.3093 10.871 16.8781 11.4282 16.5751 12.0556C16.528 12.17 16.4479 12.2679 16.3449 12.3367C16.242 12.4056 16.1208 12.4423 15.9969 12.4423C15.8729 12.4423 15.7518 12.4056 15.6488 12.3367C15.5459 12.2679 15.4658 12.17 15.4187 12.0556C15.118 11.4266 14.6874 10.8682 14.1553 10.4168C13.6231 9.96537 13.0013 9.63129 12.3307 9.4364C11.9243 9.31859 11.5036 9.25772 11.0805 9.25555Z" fill="currentColor"/></svg>
                        {{ __('wardrobe.tab_favorites') }}
                    </button>
                </div>
            @endunless
        </div>

        @if ($locked)
            {{-- Locked for Fashionista / Shopaholic: blurred preview + upsell. --}}
            <div class="locked-box wardrobe-empty" id="wardrobe-locked">
                <div class="locked-preview"><div></div><div></div><div></div></div>
                <div class="locked-overlay">
                    <div class="lock-badge"><svg width="19" height="19" viewBox="0 0 16 16" fill="none"><rect x="3.4" y="7.2" width="9.2" height="6.2" rx="1.5" stroke="#6B6B6B" stroke-width="1.2"/><path d="M5.5 7.2V5.4a2.5 2.5 0 015 0V7.2" stroke="#6B6B6B" stroke-width="1.2"/></svg></div>
                    <h3>{{ __('wardrobe.locked_title') }}</h3>
                    <p>{{ __('wardrobe.locked_text') }}</p>
                    <a class="btn-gold-outline" style="text-decoration:none;" href="{{ route('membership.pricing') }}">{{ __('wardrobe.locked_cta') }}</a>
                </div>
            </div>
        @else
            {{-- Category chips come from the feed: one per category the
                 member's own pieces are filed under (CategoryChips). --}}
            <div class="wardrobe-bar" x-show="! comparing">
                <div class="cat-chips" id="wardrobe-filter" x-ref="chips" x-on:scroll.passive="measureChips()">
                    @if ($categories)
                        <button type="button" class="cat-chip" :class="{ active: category === '' }"
                                x-on:click="category = ''; close()">{{ __('wardrobe.all') }}</button>
                    @endif
                    @foreach ($categories as $category)
                        <button type="button" class="cat-chip" :class="{ active: category === @js($category) }"
                                x-on:click="category = category === @js($category) ? '' : @js($category); close()">{{ $category }}</button>
                    @endforeach
                </div>
                {{-- Phone: the chips scroll sideways; the line under them shows where. --}}
                <div class="chip-meter only-mobile" aria-hidden="true"><span :style="chipThumb"></span></div>
                {{-- Pick 2–3 looks and see them side by side (Figma 1907:7761;
                     full width on a phone, 1874:8247). --}}
                @if ($looks->count() > 1)
                    <button type="button" class="dl-btn dl-btn-outline compare-looks" id="compare-looks"
                            x-show="tab === 'wardrobe' && open === null && ! picking" x-on:click="startPicking()">
                        <img src="{{ asset('images/lk-mob/result-compare.svg') }}" width="24" height="24" alt="">
                        {{ __('wardrobe.compare_looks') }}
                    </button>
                @endif
            </div>

            {{-- Picking looks to compare (Figma 1917:8480) --}}
            <div class="pick-banner" id="compare-banner" x-show="picking && ! comparing" x-cloak>
                <span class="pick-icon" aria-hidden="true"><img src="{{ asset('images/lk-mob/result-compare.svg') }}" width="60" height="60" alt=""></span>
                <div class="pick-text">
                    <h2>{{ __('wardrobe.pick_title') }}</h2>
                    <p>{{ __('wardrobe.pick_text') }}</p>
                </div>
                <p class="pick-count" aria-live="polite"><b x-text="picked.length"></b> / <span x-text="MAX_PICK"></span></p>
                <button type="button" class="dl-btn dl-btn-outline" x-on:click="cancelPicking()">{{ __('wardrobe.cancel') }}</button>
                <button type="button" class="dl-btn dl-btn-dark" id="compare-go" :disabled="picked.length < 2" x-on:click="comparing = true; window.scrollTo({ top: 0 })">{{ __('wardrobe.compare') }}</button>
            </div>

            {{-- The picked looks side by side, each with the pieces it was made of. --}}
            <section class="compare-view" id="compare-view" x-show="comparing" x-cloak>
                <div class="compare-view-head">
                    <button type="button" class="back" x-on:click="comparing = false">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ __('wardrobe.back_to_pick') }}
                    </button>
                    <h2>{{ __('wardrobe.comparison') }}</h2>
                    <button type="button" class="dl-btn dl-btn-outline" x-on:click="cancelPicking()">{{ __('wardrobe.done') }}</button>
                </div>
                <div class="compare-grid" :style="'--n: ' + picked.length">
                    <template x-for="id in picked" :key="id">
                        <div class="compare-col">
                            <div class="compare-pic"><img :src="lookById(id)?.after" alt=""></div>
                            <template x-for="p in lookById(id)?.pieces ?? []" :key="p.id">
                                <div class="compare-piece">
                                    <div class="text">
                                        <p class="brand" x-text="p.brand"></p>
                                        <p class="name" x-text="p.name"></p>
                                        <p class="price" x-text="p.price"></p>
                                    </div>
                                    <a class="dl-btn dl-btn-dark" :href="p.shop" target="_blank" rel="noopener">{{ __('wardrobe.buy') }}</a>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </section>

            {{-- The wardrobe opens on cards (Figma 1907:7761 on desktop, 1482:27566
                 on a phone); a card opens its set below (frame 1456:10104). --}}
            @if ($looks->isNotEmpty())
                <div class="look-cards" id="look-cards" x-show="tab === 'wardrobe' && open === null && ! comparing">
                    @foreach ($looks as $look)
                        @php($first = $look['products']->first())
                        <div class="look-card" id="look-card-{{ $look['id'] }}"
                             :class="{ 'is-picking': picking, 'is-picked': picked.includes({{ $look['id'] }}) }"
                             x-show="lookVisible({{ $look['id'] }}, @js($look['products']->map(fn ($p) => [$p->id, (string) $p->category_label])->values()->all()))">
                            <div class="pic" role="button" tabindex="0" :aria-label="picking ? @jsText(__('wardrobe.pick_for_compare')) : @jsText(__('wardrobe.view_look'))"
                                 :aria-pressed="picking ? picked.includes({{ $look['id'] }}) : null"
                                 x-on:click="cardClick({{ $look['id'] }})" x-on:keydown.enter="cardClick({{ $look['id'] }})">
                                @if ($look['after'])
                                    <img src="{{ $look['after'] }}" alt="{{ $look['title'] }}" loading="lazy">
                                @else
                                    <span class="placeholder">{{ __('wardrobe.no_image') }}</span>
                                @endif
                                <span class="pick-mark" x-show="picking" aria-hidden="true">
                                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3 7.2l2.6 2.5L11 4.3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                            </div>
                            {{-- The heart keeps the look's piece in "Обране"; the menu views,
                                 compares, downloads or removes the look (removing asks first). --}}
                            <div class="card-tools" x-show="! picking" x-data="{ menu: false }"
                                 x-on:click.outside="menu = false" x-on:keydown.escape="menu = false">
                                @if ($first)
                                    <button type="button" class="card-tool" :class="{ active: favorites.includes({{ $first->id }}) }"
                                            :aria-pressed="favorites.includes({{ $first->id }})" aria-label="{{ __('wardrobe.favorite') }}"
                                            x-on:click="toggleFavorite({{ $first->id }})">
                                        <svg width="32" height="32" viewBox="0 0 32 32" fill="none" aria-hidden="true"><path class="heart-fill" d="M16 26C15.8619 25.9996 15.7278 25.9535 15.6187 25.869C12.1431 23.175 9.74899 20.8551 8.07372 18.5663C5.93588 15.6415 5.4483 12.9412 6.62349 10.5402C7.46113 8.82525 9.86776 7.42208 12.6807 8.23904C14.0219 8.62552 15.192 9.45436 16 10.5901C16.808 9.45436 17.9781 8.62552 19.3193 8.23904C22.126 7.43455 24.5389 8.82525 25.3765 10.5402C26.5517 12.9412 26.0641 15.6415 23.9263 18.5663C22.251 20.8551 19.8569 23.175 16.3813 25.869C16.2722 25.9535 16.1381 25.9996 16 26Z" fill="none"/><path d="M16 26C15.8619 25.9996 15.7278 25.9535 15.6187 25.869C12.1431 23.175 9.74899 20.8551 8.07372 18.5663C5.93588 15.6415 5.4483 12.9412 6.62349 10.5402C7.46113 8.82525 9.86776 7.42208 12.6807 8.23904C14.0219 8.62552 15.192 9.45436 16 10.5901C16.808 9.45436 17.9781 8.62552 19.3193 8.23904C22.126 7.43455 24.5389 8.82525 25.3765 10.5402C26.5517 12.9412 26.0641 15.6415 23.9263 18.5663C22.251 20.8551 19.8569 23.175 16.3813 25.869C16.2722 25.9535 16.1381 25.9996 16 26ZM11.0805 9.25555C10.4111 9.22956 9.74735 9.38592 9.16036 9.70784C8.57338 10.0298 8.08535 10.5051 7.74867 11.0828C6.77977 13.0659 7.21734 15.2736 9.08639 17.8242C11.0719 20.3809 13.3979 22.6553 16 24.5844C18.6017 22.6572 20.9276 20.3849 22.9136 17.8305C24.7889 15.2736 25.2202 13.0659 24.2513 11.089C23.6262 9.84176 21.7509 8.85019 19.6631 9.4364C18.9936 9.63378 18.373 9.96881 17.8412 10.4199C17.3093 10.871 16.8781 11.4282 16.5751 12.0556C16.528 12.17 16.4479 12.2679 16.3449 12.3367C16.242 12.4056 16.1208 12.4423 15.9969 12.4423C15.8729 12.4423 15.7518 12.4056 15.6488 12.3367C15.5459 12.2679 15.4658 12.17 15.4187 12.0556C15.118 11.4266 14.6874 10.8682 14.1553 10.4168C13.6231 9.96537 13.0013 9.63129 12.3307 9.4364C11.9243 9.31859 11.5036 9.25772 11.0805 9.25555Z" fill="currentColor"/></svg>
                                    </button>
                                @endif
                                <button type="button" class="card-tool" aria-label="{{ __('wardrobe.look_actions') }}" aria-haspopup="menu" :aria-expanded="menu"
                                        x-on:click="menu = ! menu">
                                    <svg width="32" height="32" viewBox="0 0 32 32" fill="none" aria-hidden="true"><path d="M16.1306 13.7832C15.5079 13.7832 14.9107 14.0306 14.4704 14.4709C14.0301 14.9112 13.7827 15.5083 13.7827 16.131C13.7827 16.7537 14.0301 17.3509 14.4704 17.7912C14.9107 18.2315 15.5079 18.4789 16.1306 18.4789C16.7532 18.4789 17.3504 18.2315 17.7907 17.7912C18.231 17.3509 18.4784 16.7537 18.4784 16.131C18.4784 15.5083 18.231 14.9112 17.7907 14.4709C17.3504 14.0306 16.7532 13.7832 16.1306 13.7832ZM23.174 13.7832C22.5514 13.7832 21.9542 14.0306 21.5139 14.4709C21.0736 14.9112 20.8262 15.5083 20.8262 16.131C20.8262 16.7537 21.0736 17.3509 21.5139 17.7912C21.9542 18.2315 22.5514 18.4789 23.174 18.4789C23.7967 18.4789 24.3939 18.2315 24.8342 17.7912C25.2745 17.3509 25.5219 16.7537 25.5219 16.131C25.5219 15.5083 25.2745 14.9112 24.8342 14.4709C24.3939 14.0306 23.7967 13.7832 23.174 13.7832ZM9.08708 13.7832C8.4644 13.7832 7.86722 14.0306 7.42692 14.4709C6.98662 14.9112 6.73926 15.5083 6.73926 16.131C6.73926 16.7537 6.98662 17.3509 7.42692 17.7912C7.86722 18.2315 8.4644 18.4789 9.08708 18.4789C9.70977 18.4789 10.3069 18.2315 10.7472 17.7912C11.1876 17.3509 11.4349 16.7537 11.4349 16.131C11.4349 15.5083 11.1876 14.9112 10.7472 14.4709C10.3069 14.0306 9.70977 13.7832 9.08708 13.7832Z" fill="currentColor"/></svg>
                                </button>
                                <div class="card-menu" role="menu" x-show="menu" x-cloak>
                                    <button type="button" role="menuitem" x-on:click="menu = false; show({{ $look['id'] }})">{{ __('wardrobe.view') }}</button>
                                    @if ($looks->count() > 1)
                                        <button type="button" role="menuitem" x-on:click="menu = false; startPicking({{ $look['id'] }})">{{ __('wardrobe.compare') }}</button>
                                    @endif
                                    <a role="menuitem" href="{{ route('membership.account.looks.download', ['generation' => $look['id']]) }}" download x-on:click="menu = false">{{ __('wardrobe.download') }}</a>
                                    <button type="button" role="menuitem" class="danger" x-on:click="menu = false; removeLook({{ $look['id'] }})">{{ __('wardrobe.delete') }}</button>
                                </div>
                            </div>
                            {{-- Under the look, its piece (Figma 1907:7903): brand, name and
                                 price from the product row, then the try-on with it picked
                                 and the shop. --}}
                            @if ($first)
                                <div class="text">
                                    <p class="brand">{{ $first->brand_name }}</p>
                                    <h3 class="name" title="{{ $first->product_name }}">{{ $first->product_name }}</h3>
                                    <p class="price">{{ number_format((float) $first->price_aed, 2) }} AED</p>
                                </div>
                                <div class="actions" x-show="! picking">
                                    @if (in_array($first->category, \App\Models\Product::TRY_ON_CATEGORIES, true))
                                        <a class="dl-btn dl-btn-outline" href="{{ route('membership.account.dashboard', ['pick' => $first->id, 'plan' => $plan]) }}#catalog-grid">{{ __('wardrobe.try_on') }}</a>
                                    @else
                                        <button type="button" class="dl-btn dl-btn-outline" disabled title="{{ __('wardrobe.try_on_unavailable') }}">{{ __('wardrobe.try_on') }}</button>
                                    @endif
                                    <a class="dl-btn dl-btn-dark" href="{{ route('membership.account.shop', ['product' => $first->id, 'from' => 'wardrobe']) }}" target="_blank" rel="noopener">{{ __('wardrobe.buy') }}</a>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @forelse ($looks as $look)
                <article class="look-set" id="look-{{ $look['id'] }}" x-cloak
                         x-show="open === {{ $look['id'] }} && lookVisible({{ $look['id'] }}, @js($look['products']->map(fn ($p) => [$p->id, (string) $p->category_label])->values()->all()))">
                    {{-- Desktop: the remove action sits above the set (frame 1456:10104). --}}
                    <div class="look-set-tools only-desktop">
                        <button type="button" class="back" x-on:click="close()">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            {{ __('wardrobe.all_looks') }}
                        </button>
                        <button type="button" class="remove-set" x-on:click="removeLook({{ $look['id'] }})">{{ __('wardrobe.delete_set') }}</button>
                        <button type="button" class="icon-circle" aria-label="{{ __('wardrobe.delete_set') }}" x-on:click="removeLook({{ $look['id'] }})">
                            <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true"><path d="M6.5 6.5l9 9M15.5 6.5l-9 9" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                        </button>
                    </div>
                    {{-- Phone (Figma 1954:9893): under the page head, only the way back. --}}
                    <div class="look-set-head only-mobile">
                        <button type="button" class="back" x-on:click="close()">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            {{ __('wardrobe.all_looks') }}
                        </button>
                    </div>
                    <div class="look-set-card">
                        <div>
                            <div class="look-visual" x-data="{ split: 50 }" :style="'--split: ' + split + '%'">
                                @if ($look['before'] && $look['after'])
                                    <div class="compare">
                                        <img src="{{ $look['before'] }}" alt="{{ __('wardrobe.before') }}">
                                        <img class="after" src="{{ $look['after'] }}" alt="{{ __('wardrobe.after') }}">
                                        <span class="ba-label ba-before only-mobile">{{ __('wardrobe.before') }}</span>
                                        <span class="ba-label ba-after only-mobile">{{ __('wardrobe.after') }}</span>
                                        <span class="bar"></span>
                                        <span class="knob" aria-hidden="true">
                                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="#2D2D2D" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M6 3l5 5-5 5" stroke="#2D2D2D" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </span>
                                        <input type="range" min="0" max="100" x-model="split" aria-label="{{ __('wardrobe.compare_slider') }}">
                                    </div>
                                @elseif ($look['after'])
                                    <img src="{{ $look['after'] }}" alt="{{ $look['title'] }}">
                                @else
                                    <span class="placeholder">{{ __('wardrobe.no_image') }}</span>
                                @endif
                            </div>
                            {{-- Under the compare (Figma 1954:9485): the four actions the
                                 phone shows as its tiles. --}}
                            @php($lead = $look['products']->first())
                            <div class="set-tiles only-desktop">
                                @if ($lead)
                                    <button type="button" class="set-tile" :aria-pressed="favorites.includes({{ $lead->id }})"
                                            x-on:click="toggleFavorite({{ $lead->id }})">
                                        <img src="{{ asset('images/lk-mob/heart-filled.svg') }}" alt="" x-show="favorites.includes({{ $lead->id }})" x-cloak>
                                        <img src="{{ asset('images/lk-mob/result-heart.svg') }}" alt="" x-show="! favorites.includes({{ $lead->id }})">
                                        {{ __('wardrobe.save') }}
                                    </button>
                                @endif
                                @if ($looks->count() > 1)
                                    <button type="button" class="set-tile" x-on:click="close(); startPicking({{ $look['id'] }})">
                                        <img src="{{ asset('images/lk-mob/result-compare.svg') }}" alt="">{{ __('wardrobe.compare') }}
                                    </button>
                                @endif
                                @if ($look['after'])
                                    <button type="button" class="set-tile" x-on:click="shareLook(@js($look['after']))">
                                        <img src="{{ asset('images/lk-mob/result-share.svg') }}" alt="">{{ __('wardrobe.share') }}
                                    </button>
                                @endif
                                <a class="set-tile" href="{{ route('membership.account.looks.download', ['generation' => $look['id']]) }}" download>
                                    <img src="{{ asset('images/lk-mob/result-download.svg') }}" alt="">{{ __('wardrobe.download') }}
                                </a>
                            </div>
                            <p class="set-copied only-desktop" x-show="copied" x-cloak>{{ __('wardrobe.copied') }}</p>
                            <p class="set-note only-desktop">{{ __('wardrobe.safe_note') }}</p>
                        </div>
                        <div class="pieces">
                            @foreach ($look['products'] as $product)
                                {{-- Brand and shop link from the product row, never from the template. --}}
                                <div class="piece" id="piece-{{ $look['id'] }}-{{ $product->id }}"
                                     data-category="{{ $product->category_label }}"
                                     x-show="pieceVisible({{ $look['id'] }}, {{ $product->id }}, @js((string) $product->category_label))">
                                    <div class="pic">
                                        <img src="{{ $product->image_flat_url }}" alt="{{ $product->product_name }}" loading="lazy">
                                    </div>
                                    <div class="body">
                                        <div class="row">
                                            <span class="brand">{{ $product->brand_name }}</span>
                                            <span class="icons">
                                                <button type="button" class="icon-btn" :class="{ active: favorites.includes({{ $product->id }}) }"
                                                        :aria-pressed="favorites.includes({{ $product->id }})" aria-label="{{ __('wardrobe.favorite') }}"
                                                        x-on:click="toggleFavorite({{ $product->id }})">
                                                    <svg viewBox="0 0 28 28" fill="none" aria-hidden="true"><path class="heart-fill" d="M14 23.5S3.5 17.4 3.5 10.3A5.3 5.3 0 0114 7.6a5.3 5.3 0 0110.5 2.7c0 7.1-10.5 13.2-10.5 13.2z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" fill="none"/></svg>
                                                </button>
                                                <button type="button" class="icon-btn" aria-label="{{ __('wardrobe.remove_piece') }}" x-on:click="removePiece({{ $look['id'] }}, {{ $product->id }})">
                                                    <svg viewBox="0 0 28 28" fill="none" aria-hidden="true"><circle cx="14" cy="14" r="11" stroke="currentColor" stroke-width="1.4"/><path d="M10 10l8 8M18 10l-8 8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                                                </button>
                                            </span>
                                        </div>
                                        <h3 class="name">{{ $product->product_name }}</h3>
                                        <p class="price">{{ number_format((float) $product->price_aed, 2) }} AED</p>
                                    </div>
                                    {{-- Figma 1954:9485: the shop, then the try-on for another piece. --}}
                                    <div class="piece-actions">
                                        <a class="dl-btn dl-btn-dark dl-btn-block" href="{{ route('membership.account.shop', ['product' => $product->id, 'from' => 'wardrobe']) }}" target="_blank" rel="noopener">
                                            <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true"><path d="M12 4h6v6M18 4l-8 8M16 13v4a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            {{ __('wardrobe.go_to_shop') }}
                                        </a>
                                        <a class="dl-btn dl-btn-outline dl-btn-block" href="{{ route('membership.account.dashboard', ['plan' => $plan]) }}#catalog-panel">
                                            <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true"><path d="M17.5 11a6.5 6.5 0 11-1.9-4.6M17.5 4v3.5H14" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            {{ __('wardrobe.try_another') }}
                                        </a>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    {{-- Desktop (Figma 1954:9485): the stylist, under the set. --}}
                    <div class="set-ask only-desktop">
                        <img class="set-ask-pic" src="{{ asset('images/lk-mob/ask-stylist.png') }}" alt="">
                        <div class="set-ask-text">
                            <h2>{{ __('dashboard.ask_title') }}</h2>
                            <p>{{ __('dashboard.ask_sub') }}</p>
                        </div>
                        @if ($bookingUrl)
                            <a class="dl-btn dl-btn-dark" href="{{ $bookingUrl }}" target="_blank" rel="noopener">{{ __('dashboard.ask_cta') }}</a>
                        @else
                            <button type="button" class="dl-btn dl-btn-dark" disabled>{{ __('dashboard.ask_cta') }}</button>
                        @endif
                    </div>
                    {{-- Phone (Figma 1954:9893): the try-on result's own layout —
                         four actions, a card per piece, the stylist. Removing the
                         set lives in the card's menu. --}}
                    @php($first = $look['products']->first())
                    <div class="m-set only-mobile" id="m-set-{{ $look['id'] }}">
                        <div class="m-tiles">
                            {{-- Keeps the look's piece in "Обране", as the card's heart does. --}}
                            @if ($first)
                                <button type="button" class="m-tile" :aria-pressed="favorites.includes({{ $first->id }})"
                                        x-on:click="toggleFavorite({{ $first->id }})">
                                    <img src="{{ asset('images/lk-mob/heart-filled.svg') }}" alt="" x-show="favorites.includes({{ $first->id }})" x-cloak>
                                    <img src="{{ asset('images/lk-mob/result-heart.svg') }}" alt="" x-show="! favorites.includes({{ $first->id }})">
                                    {{ __('wardrobe.save') }}
                                </button>
                            @endif
                            @if ($looks->count() > 1)
                                <button type="button" class="m-tile" x-on:click="close(); startPicking({{ $look['id'] }})">
                                    <img src="{{ asset('images/lk-mob/result-compare.svg') }}" alt="">{{ __('wardrobe.compare') }}
                                </button>
                            @endif
                            @if ($look['after'])
                                <button type="button" class="m-tile" x-on:click="shareLook(@js($look['after']))">
                                    <img src="{{ asset('images/lk-mob/result-share.svg') }}" alt="">{{ __('wardrobe.share') }}
                                </button>
                            @endif
                            <a class="m-tile" href="{{ route('membership.account.looks.download', ['generation' => $look['id']]) }}" download>
                                <img src="{{ asset('images/lk-mob/result-download.svg') }}" alt="">{{ __('wardrobe.download') }}
                            </a>
                        </div>
                        <p class="m-copied" x-show="copied" x-cloak>{{ __('wardrobe.copied') }}</p>

                        @foreach ($look['products'] as $product)
                            {{-- Brand and shop link from the product row, never from the template. --}}
                            <div class="m-item" id="m-piece-{{ $look['id'] }}-{{ $product->id }}"
                                 x-show="pieceVisible({{ $look['id'] }}, {{ $product->id }}, @js((string) $product->category_label))">
                                <div class="m-item-pic"><img src="{{ $product->image_flat_url }}" alt="{{ $product->product_name }}" loading="lazy"></div>
                                <div class="m-item-text">
                                    <p class="m-item-brand">{{ $product->brand_name }}</p>
                                    <p class="m-item-name">{{ $product->product_name }}</p>
                                    <p class="m-item-price">{{ number_format((float) $product->price_aed, 2) }} AED</p>
                                </div>
                                <button type="button" class="m-item-like" :aria-pressed="favorites.includes({{ $product->id }})" aria-label="{{ __('wardrobe.favorite') }}"
                                        x-on:click="toggleFavorite({{ $product->id }})">
                                    <img src="{{ asset('images/lk-mob/heart-filled.svg') }}" width="18" height="18" alt="" x-show="favorites.includes({{ $product->id }})" x-cloak>
                                    <img src="{{ asset('images/lk-mob/result-heart.svg') }}" width="18" height="18" alt="" x-show="! favorites.includes({{ $product->id }})">
                                </button>
                                <div class="m-item-actions">
                                    <a class="m-btn m-btn-outline" href="{{ route('membership.account.dashboard', ['plan' => $plan]) }}#catalog-panel">{{ __('wardrobe.try_another_short') }}</a>
                                    <a class="m-btn m-btn-dark" href="{{ route('membership.account.shop', ['product' => $product->id, 'from' => 'wardrobe']) }}" target="_blank" rel="noopener">{{ __('wardrobe.buy') }}</a>
                                </div>
                            </div>
                        @endforeach

                        {{-- With no booking link set the button stays inert rather
                             than pointing somewhere made up. --}}
                        <div class="m-ask">
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
                </article>
            @empty
                {{-- Empty (Figma 1906:11347): the rail, the line, and two ways
                     into the try-on — at the photo, or straight at the catalog. --}}
                <div class="wardrobe-empty-box wardrobe-empty" id="wardrobe-empty" x-show="tab === 'wardrobe'">
                    @if (file_exists(public_path('images/wardrobe-empty.png')))
                        <img class="empty-pic" src="{{ asset('images/wardrobe-empty.png') }}" width="234" height="268" alt="">
                    @endif
                    <h2>{{ __('wardrobe.empty_title') }}</h2>
                    <p>{{ __('wardrobe.empty_text') }}</p>
                    <div class="empty-actions">
                        <a class="dl-btn dl-btn-dark" id="empty-start" href="{{ route('membership.account.dashboard', ['plan' => $plan]) }}#photo-panel">
                            <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true"><path d="M11 3l1.9 4.6L17.5 9.5l-4.6 1.9L11 16l-1.9-4.6L4.5 9.5l4.6-1.9L11 3z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M17 14l.7 1.6 1.6.7-1.6.7L17 18.6l-.7-1.6-1.6-.7 1.6-.7L17 14z" stroke="currentColor" stroke-width="1.1" stroke-linejoin="round"/></svg>
                            {{ __('wardrobe.empty_start') }}
                        </a>
                        <a class="dl-btn dl-btn-outline" id="empty-catalog" href="{{ route('membership.account.dashboard', ['plan' => $plan]) }}#catalog-panel">
                            <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true"><path d="M8 3.5c0 1.5 1.3 2.5 3 2.5s3-1 3-2.5M8.5 5.5L3 9l2 3 2.5-1v8h7v-8l2.5 1 2-3-5.5-3.5" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                            {{ __('wardrobe.empty_go') }}
                        </a>
                    </div>
                </div>
            @endforelse

            {{-- "Обране" (Figma 1907:7903): every hearted piece from the account —
                 from the catalog, the try-on or a set above — as cards: the
                 picture with a filled heart and a menu on white rounds, brand,
                 name and price, then "Приміряти" and "Купити". --}}
            <div class="fav-cards" id="favorites-list" x-show="tab === 'favorites'" x-cloak>
                @foreach ($favorites as $product)
                    @php($canTry = in_array($product->category, \App\Models\Product::TRY_ON_CATEGORIES, true))
                    @php($tryUrl = route('membership.account.dashboard', ['pick' => $product->id, 'plan' => $plan]).'#catalog-grid')
                    @php($shopUrl = route('membership.account.shop', ['product' => $product->id, 'from' => 'wardrobe']))
                    <article class="fav-card" id="fav-{{ $product->id }}"
                             x-show="favorites.includes({{ $product->id }}) && (! category || category === @js((string) $product->category_label))">
                        <div class="pic">
                            <img src="{{ $product->image_flat_url }}" alt="{{ $product->product_name }}" loading="lazy">
                            <div class="card-tools" x-data="{ menu: false }" x-on:click.outside="menu = false" x-on:keydown.escape="menu = false">
                                <button type="button" class="card-tool active" aria-pressed="true" aria-label="{{ __('wardrobe.unfavorite') }}"
                                        x-on:click="toggleFavorite({{ $product->id }})">
                                    <svg width="32" height="32" viewBox="0 0 32 32" fill="none" aria-hidden="true"><path class="heart-fill" d="M16 26C15.8619 25.9996 15.7278 25.9535 15.6187 25.869C12.1431 23.175 9.74899 20.8551 8.07372 18.5663C5.93588 15.6415 5.4483 12.9412 6.62349 10.5402C7.46113 8.82525 9.86776 7.42208 12.6807 8.23904C14.0219 8.62552 15.192 9.45436 16 10.5901C16.808 9.45436 17.9781 8.62552 19.3193 8.23904C22.126 7.43455 24.5389 8.82525 25.3765 10.5402C26.5517 12.9412 26.0641 15.6415 23.9263 18.5663C22.251 20.8551 19.8569 23.175 16.3813 25.869C16.2722 25.9535 16.1381 25.9996 16 26Z" fill="none"/><path d="M16 26C15.8619 25.9996 15.7278 25.9535 15.6187 25.869C12.1431 23.175 9.74899 20.8551 8.07372 18.5663C5.93588 15.6415 5.4483 12.9412 6.62349 10.5402C7.46113 8.82525 9.86776 7.42208 12.6807 8.23904C14.0219 8.62552 15.192 9.45436 16 10.5901C16.808 9.45436 17.9781 8.62552 19.3193 8.23904C22.126 7.43455 24.5389 8.82525 25.3765 10.5402C26.5517 12.9412 26.0641 15.6415 23.9263 18.5663C22.251 20.8551 19.8569 23.175 16.3813 25.869C16.2722 25.9535 16.1381 25.9996 16 26ZM11.0805 9.25555C10.4111 9.22956 9.74735 9.38592 9.16036 9.70784C8.57338 10.0298 8.08535 10.5051 7.74867 11.0828C6.77977 13.0659 7.21734 15.2736 9.08639 17.8242C11.0719 20.3809 13.3979 22.6553 16 24.5844C18.6017 22.6572 20.9276 20.3849 22.9136 17.8305C24.7889 15.2736 25.2202 13.0659 24.2513 11.089C23.6262 9.84176 21.7509 8.85019 19.6631 9.4364C18.9936 9.63378 18.373 9.96881 17.8412 10.4199C17.3093 10.871 16.8781 11.4282 16.5751 12.0556C16.528 12.17 16.4479 12.2679 16.3449 12.3367C16.242 12.4056 16.1208 12.4423 15.9969 12.4423C15.8729 12.4423 15.7518 12.4056 15.6488 12.3367C15.5459 12.2679 15.4658 12.17 15.4187 12.0556C15.118 11.4266 14.6874 10.8682 14.1553 10.4168C13.6231 9.96537 13.0013 9.63129 12.3307 9.4364C11.9243 9.31859 11.5036 9.25772 11.0805 9.25555Z" fill="currentColor"/></svg>
                                </button>
                                <button type="button" class="card-tool" aria-label="{{ __('wardrobe.piece_actions') }}" aria-haspopup="menu" :aria-expanded="menu"
                                        x-on:click="menu = ! menu">
                                    <svg width="32" height="32" viewBox="0 0 32 32" fill="none" aria-hidden="true"><path d="M16.1306 13.7832C15.5079 13.7832 14.9107 14.0306 14.4704 14.4709C14.0301 14.9112 13.7827 15.5083 13.7827 16.131C13.7827 16.7537 14.0301 17.3509 14.4704 17.7912C14.9107 18.2315 15.5079 18.4789 16.1306 18.4789C16.7532 18.4789 17.3504 18.2315 17.7907 17.7912C18.231 17.3509 18.4784 16.7537 18.4784 16.131C18.4784 15.5083 18.231 14.9112 17.7907 14.4709C17.3504 14.0306 16.7532 13.7832 16.1306 13.7832ZM23.174 13.7832C22.5514 13.7832 21.9542 14.0306 21.5139 14.4709C21.0736 14.9112 20.8262 15.5083 20.8262 16.131C20.8262 16.7537 21.0736 17.3509 21.5139 17.7912C21.9542 18.2315 22.5514 18.4789 23.174 18.4789C23.7967 18.4789 24.3939 18.2315 24.8342 17.7912C25.2745 17.3509 25.5219 16.7537 25.5219 16.131C25.5219 15.5083 25.2745 14.9112 24.8342 14.4709C24.3939 14.0306 23.7967 13.7832 23.174 13.7832ZM9.08708 13.7832C8.4644 13.7832 7.86722 14.0306 7.42692 14.4709C6.98662 14.9112 6.73926 15.5083 6.73926 16.131C6.73926 16.7537 6.98662 17.3509 7.42692 17.7912C7.86722 18.2315 8.4644 18.4789 9.08708 18.4789C9.70977 18.4789 10.3069 18.2315 10.7472 17.7912C11.1876 17.3509 11.4349 16.7537 11.4349 16.131C11.4349 15.5083 11.1876 14.9112 10.7472 14.4709C10.3069 14.0306 9.70977 13.7832 9.08708 13.7832Z" fill="currentColor"/></svg>
                                </button>
                                <div class="card-menu" role="menu" x-show="menu" x-cloak>
                                    @if ($canTry)
                                        <a role="menuitem" href="{{ $tryUrl }}">{{ __('wardrobe.try_on') }}</a>
                                    @endif
                                    <a role="menuitem" href="{{ $shopUrl }}" target="_blank" rel="noopener" x-on:click="menu = false">{{ __('wardrobe.go_to_shop') }}</a>
                                    <button type="button" role="menuitem" class="danger" x-on:click="menu = false; toggleFavorite({{ $product->id }})">{{ __('wardrobe.unfavorite') }}</button>
                                </div>
                            </div>
                        </div>
                        <div class="text">
                            <p class="brand">{{ $product->brand_name }}</p>
                            <h3 class="name" title="{{ $product->product_name }}">{{ $product->product_name }}</h3>
                            <p class="price">{{ number_format((float) $product->price_aed, 2) }} AED</p>
                        </div>
                        <div class="actions">
                            @if ($canTry)
                                <a class="dl-btn dl-btn-outline" href="{{ $tryUrl }}">{{ __('wardrobe.try_on') }}</a>
                            @else
                                <button type="button" class="dl-btn dl-btn-outline" disabled title="{{ __('wardrobe.try_on_unavailable') }}">{{ __('wardrobe.try_on') }}</button>
                            @endif
                            <a class="dl-btn dl-btn-dark" href="{{ $shopUrl }}" target="_blank" rel="noopener">{{ __('wardrobe.buy') }}</a>
                        </div>
                    </article>
                @endforeach
            </div>
            <p class="fav-empty" id="favorites-empty" x-show="tab === 'favorites' && favorites.length === 0" x-cloak>
                {{ __('wardrobe.favorites_empty') }}
            </p>
        @endif

        {{-- Removing a look asks first (Figma 1878:7507). Teleported to <body>:
             the section's rise animation leaves a transform, which would pin a
             fixed overlay to the section instead of the screen. --}}
        <template x-teleport="body">
            <div class="confirm-scrim" id="delete-look-dialog" x-show="toDelete !== null" x-cloak
                 x-on:click.self="toDelete = null" x-on:keydown.escape.window="toDelete = null">
                <div class="confirm-card" role="alertdialog" aria-modal="true" aria-labelledby="delete-look-title" aria-describedby="delete-look-text">
                    <span class="confirm-icon" aria-hidden="true">
                        <svg width="46" height="46" viewBox="0 0 28 28" fill="none"><path d="M7 8.5h14M11 8.5V6.5h6v2M9 8.5l.8 13h8.4l.8-13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 12v6M16 12v6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    </span>
                    <h2 id="delete-look-title">{{ __('wardrobe.delete_title') }}</h2>
                    <p id="delete-look-text">{{ __('wardrobe.delete_text') }}</p>
                    <div class="confirm-actions">
                        <button type="button" class="dl-btn dl-btn-outline" x-on:click="toDelete = null">{{ __('wardrobe.cancel') }}</button>
                        <button type="button" class="dl-btn dl-btn-dark" id="delete-look-confirm" x-on:click="confirmRemove()">{{ __('wardrobe.delete') }}</button>
                    </div>
                </div>
            </div>
        </template>
    </div>
@endsection

@section('scripts')
    <script>
        function wardrobe() {
            return {
                // #favorites (the try-on screen's "Улюблене" tab) opens on the hearts.
                tab: location.hash === '#favorites' ? 'favorites' : 'wardrobe',
                // The set opened from its card (null = the cards). Kept in the
                // hash, so the browser's back button returns to the cards.
                open: null,
                // Active category chip ('' = all).
                category: '',
                // Hearted pieces, from the account — the dashboard's hearts.
                favorites: @json($favorites->pluck('id')->values()),
                favoriteRoute: @json($favoriteRoute),
                // Taken out this visit; the server already leaves out what was
                // taken out before. Pieces are "look-product", per set.
                removedLooks: [],
                removedPieces: [],
                hideRoutes: @json($hideRoutes),
                // Comparing looks (desktop): picking 2–3 cards, then side by side.
                picking: false,
                picked: [],
                MAX_PICK: 3,
                comparing: false,
                looks: @json($compareLooks),

                // The phone's line under the chips: thumb width and offset.
                chipThumb: '',
                measureChips() {
                    const row = this.$refs.chips;
                    // Nothing to scroll: no thumb, the line stays a quiet track.
                    if (! row || row.scrollWidth <= row.clientWidth) { this.chipThumb = 'width: 0'; return; }
                    const width = row.clientWidth / row.scrollWidth * 100;
                    const left = Math.abs(row.scrollLeft) / row.scrollWidth * 100; // negative in rtl
                    this.chipThumb = `width: ${width}%; margin-inline-start: ${left}%`;
                },

                init() {
                    this.$nextTick(() => this.measureChips());
                    window.addEventListener('resize', () => this.measureChips());
                    const sync = () => {
                        const id = parseInt((location.hash.match(/^#set-(\d+)$/) || [])[1], 10);
                        this.open = Number.isNaN(id) ? null : id;
                    };
                    sync();
                    window.addEventListener('hashchange', sync);
                },
                show(id) {
                    location.hash = `set-${id}`;
                    window.scrollTo({ top: 0 });
                },
                close() {
                    if (this.open === null) return;
                    this.open = null;
                    history.pushState(null, '', location.pathname + location.search);
                },

                // A card opens its set, or — while picking — joins / leaves the pick.
                cardClick(id) {
                    if (! this.picking) { this.show(id); return; }
                    if (this.picked.includes(id)) {
                        this.picked = this.picked.filter((p) => p !== id);
                    } else if (this.picked.length < this.MAX_PICK) {
                        this.picked = [...this.picked, id];
                    }
                },
                startPicking(id = null) {
                    this.picking = true;
                    this.comparing = false;
                    this.picked = id ? [id] : [];
                },
                cancelPicking() {
                    this.picking = false;
                    this.comparing = false;
                    this.picked = [];
                },
                lookById(id) {
                    return this.looks.find((look) => look.id === id) ?? null;
                },

                // Optimistic: flips at once, flips back if the server says no.
                async toggleFavorite(id) {
                    const liking = ! this.favorites.includes(id);
                    const flip = (on) => {
                        this.favorites = on ? [...this.favorites, id] : this.favorites.filter(f => f !== id);
                    };
                    flip(liking);
                    try {
                        const response = await fetch(this.favoriteRoute.replace(/\/0$/, `/${id}`), {
                            method: liking ? 'POST' : 'DELETE',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                        });
                        if (! response.ok) flip(! liking);
                    } catch (e) {
                        flip(! liking);
                    }
                },
                // A look's own link (a short-lived signed URL): the share sheet,
                // or the clipboard where there is none.
                copied: false,
                async shareLook(url) {
                    if (navigator.share) {
                        try { await navigator.share({ url }); return; } catch (e) { /* dismissed */ }
                    }
                    try { await navigator.clipboard.writeText(url); } catch (e) { return; }
                    this.copied = true;
                    setTimeout(() => { this.copied = false; }, 2400);
                },
                // Removing a look asks first (the dialog); the dialog's button removes.
                toDelete: null,
                removeLook(id) {
                    this.toDelete = id;
                },
                // Optimistic: gone at once, back if the server says no.
                async confirmRemove() {
                    const id = this.toDelete;
                    if (id === null) return;
                    this.toDelete = null;
                    this.removedLooks = [...this.removedLooks, id];
                    this.picked = this.picked.filter((p) => p !== id);
                    if (this.open === id) this.close();
                    if (! await this.hide(this.hideRoutes.look.replace('/looks/0', `/looks/${id}`))) {
                        this.removedLooks = this.removedLooks.filter(l => l !== id);
                    }
                },
                async removePiece(look, id) {
                    const key = `${look}-${id}`;
                    this.removedPieces = [...this.removedPieces, key];
                    const url = this.hideRoutes.piece.replace('/looks/0/pieces/0', `/looks/${look}/pieces/${id}`);
                    if (! await this.hide(url)) {
                        this.removedPieces = this.removedPieces.filter(p => p !== key);
                    }
                },
                async hide(url) {
                    try {
                        const response = await fetch(url, {
                            method: 'DELETE',
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
                pieceVisible(look, id, category) {
                    if (this.removedPieces.includes(`${look}-${id}`)) return false;
                    if (this.category && category !== this.category) return false;
                    return this.tab === 'wardrobe' || this.favorites.includes(id);
                },
                // pieces: [[id, category], ...] — a set stays while any of its
                // pieces is visible under the current tab and chip.
                lookVisible(id, pieces) {
                    // "Обране" has its own list below the sets.
                    if (this.tab === 'favorites') return false;
                    if (this.removedLooks.includes(id)) return false;
                    return pieces.some(([p, c]) => this.pieceVisible(id, p, c));
                },
            };
        }
    </script>
@endsection

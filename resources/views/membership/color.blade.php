@extends('membership.shell')

@section('title', 'Atelier Fit — Кольоротип')

{{-- "Кольоротип": the member's own colour season.

     Two faces. Before there is a season — and while one is being worked out,
     refused or retaken — it is the intake: the photo panel beside a result box,
     in the language of Desk_lk. Once there is a season it becomes a sheet,
     after the stylist's own colour card: the portrait beside the season's name,
     the four groups as cards, then harmony, tips and the stylist's signature.

     The one thing on this page that is allowed to have colour is the person's
     own palette — every swatch is data from the season rows, never a literal
     here (AppearanceTest).

     What the member never sees: scores, axes, zones, white balance, debug
     views. The engine's numbers stop at MemberColorPresenter. --}}

@section('styles')
        .color-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; margin-top: 40px; align-items: start; }
        @media (min-width: 834px) { .color-grid { grid-template-columns: minmax(0, 5fr) minmax(0, 7fr); } }
        @media (min-width: 1280px) { .color-grid { grid-template-columns: minmax(0, 420fr) minmax(0, 760fr); gap: 40px; margin-top: 26px; } }
        .panel { min-width: 0; border: 1px solid var(--line-soft); padding: 12px; background: var(--surface); }
        .panel-label { font-size: 16px; line-height: 1.5; color: var(--ink-2); margin: 0 0 12px; }
        @media (min-width: 1280px) { .panel { border: none; padding: 0; } .panel-label { font-size: 14px; margin-bottom: 8px; } }

        /* Photo: a portrait frame, the dropzone of the try-on page */
        .frame-3x4 { position: relative; aspect-ratio: 4 / 5; overflow: hidden; background: var(--surface-2); min-height: 251px; max-height: 520px; }
        /* Cropped from near the top, not the centre: a tall selfie keeps the head, loses the chest */
        .frame-3x4 img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: 50% 15%; }
        .frame-3x4.is-over { outline: 2px dashed var(--ink-2); outline-offset: -2px; }
        .frame-3x4 .retake { position: absolute; right: 10px; bottom: 10px; padding: 8px 14px; border: none; border-radius: 0;
            background: rgba(255, 255, 255, .92); color: var(--ink-2); font-family: 'Montserrat', sans-serif; font-size: 11px;
            letter-spacing: .12em; text-transform: uppercase; cursor: pointer; }
        .dropzone { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center;
            justify-content: center; gap: 12px; padding: 24px 32px; text-align: center;
            background: var(--surface-2); border: 1px dashed var(--line-soft); }
        .dropzone .mark { width: 32px; height: 32px; border-radius: 50%; border: 1px solid var(--ink-2);
            display: flex; align-items: center; justify-content: center; margin-bottom: 8px; }
        .dropzone p { margin: 0; font-size: 14px; line-height: 1.5; max-width: 252px; }
        .dropzone .dz-title { font-weight: 400; text-transform: uppercase; color: var(--ink-2); }
        .dropzone .dz-hint { color: var(--muted); }
        .dropzone .dz-btn { margin-top: 8px; min-height: 45px; padding: 12px 38px; font-size: 14px; letter-spacing: .13em; }
        .dropzone .dz-formats { font-size: 12px; color: var(--muted); }

        /* How to shoot: three short rules, each one a thing the engine has refused a photo for */
        .shot-rules { list-style: none; margin: 16px 0 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
        .shot-rules li { display: flex; gap: 12px; align-items: flex-start; font-size: 14px; line-height: 1.5; color: var(--muted); }
        .shot-rules li::before { content: ''; flex: none; width: 6px; height: 6px; border-radius: 50%; margin-top: 8px; background: var(--ink-2); }

        /* One question, two answers */
        .hair-q { margin: 20px 0 0; padding: 0; border: none; }
        .hair-q legend { padding: 0; margin-bottom: 8px; font-size: 14px; color: var(--ink-2); }
        .hair-q .answers { display: flex; gap: 8px; }
        .hair-q button { padding: 8px 18px; border: 1px solid var(--line-soft); border-radius: 0; background: none; cursor: pointer;
            font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 14px; color: var(--ink-2); transition: background .25s ease, color .25s ease, border-color .25s ease; }
        .hair-q button:hover { border-color: var(--ink-2); }
        .hair-q button.active { background: var(--ink-2); border-color: var(--ink-2); color: var(--surface); }
        .hair-q .previous { margin-top: 14px; }
        .hair-q .prev-q { margin: 0 0 8px; font-size: 13px; color: var(--ink-2); }
        .hair-q .answers { flex-wrap: wrap; }
        .hair-q .why { margin: 8px 0 0; font-size: 12px; line-height: 1.5; color: var(--muted); }

        /* Consent: a real checkbox, the sentence beside it, the policy linked */
        .consent { display: flex; gap: 12px; align-items: flex-start; margin: 20px 0 0; padding: 14px 15px; background: var(--surface-2); cursor: pointer; }
        .consent input { flex: none; width: 18px; height: 18px; margin: 2px 0 0; accent-color: #2D2D2D; }
        .consent span { font-size: 13px; line-height: 1.5; color: var(--muted); }
        .consent a { color: var(--ink-2); }
        .color-submit { margin-top: 16px; }
        .form-error { margin: 10px 0 0; font-size: 13px; line-height: 1.5; color: var(--danger); }

        /* Result panel: one box, five states */
        /* A grid with one cell, so whichever state is shown fills the box */
        .result-box { position: relative; display: grid; min-height: 251px; background: var(--surface-2); }
        @media (min-width: 834px) { .result-box { min-height: 420px; } }
        .result-box .state { padding: 28px 24px; min-width: 0; }
        @media (min-width: 1280px) { .result-box .state { padding: 36px 40px; } }
        .result-box .state.centered { display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;
            gap: 12px; padding: 24px 40px; border: 1px dashed var(--line-soft); }
        .result-box .state p { margin: 0; font-size: 14px; line-height: 1.5; max-width: 30rem; }
        .result-box .state .title { font-weight: 400; text-transform: uppercase; color: var(--ink-2); }
        .result-box .state .hint { color: var(--muted); }
        .result-box .state .mark { width: 32px; height: 32px; border-radius: 50%; border: 1px solid var(--ink-2);
            display: flex; align-items: center; justify-content: center; margin-bottom: 8px; }
        .result-box .retry { margin-top: 12px; }

        /* Working: a slow shimmer across the box, nothing else moves */
        .working-bar { width: 160px; height: 3px; border-radius: 0; margin-bottom: 8px;
            background: linear-gradient(90deg, rgba(45, 45, 45, .08) 0, rgba(45, 45, 45, .35) 50%, rgba(45, 45, 45, .08) 100%);
            background-size: 320px 100%; animation: nshimmer 1.6s linear infinite; }
        @media (prefers-reduced-motion: reduce) { .working-bar { animation: none; background: rgba(45, 45, 45, .2); } }

        /* ---- The sheet ---------------------------------------------------- */
        /* Capped: a sheet is read as one card, not stretched across a wide screen */
        .sheet { --script: 'Great Vibes', cursive; max-width: 1240px; }
        .sheet-hero { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; align-items: stretch; }
        @media (min-width: 1280px) { .sheet-hero { grid-template-columns: minmax(0, 360fr) minmax(0, 820fr); gap: 48px; } }

        /* The portrait: the member's photo while it exists, their palette after */
        /* align-self: a stretched height would widen the frame through aspect-ratio, out of its column */
        .portrait { position: relative; align-self: start; aspect-ratio: 4 / 5; overflow: hidden; background: var(--surface-2); }
        @media (max-width: 1279px) { .portrait { max-width: 420px; } }
        .portrait img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: 50% 15%; }
        .portrait .mosaic { position: absolute; inset: 0; display: grid; grid-template-columns: repeat(3, 1fr); }
        .portrait .mosaic span { display: block; }
        .portrait::after { content: ''; position: absolute; inset: 45% 0 0; pointer-events: none;
            background: linear-gradient(to bottom, rgba(45, 45, 45, 0), rgba(45, 45, 45, .62)); }
        .portrait .caption { position: absolute; left: 24px; right: 24px; bottom: 20px; z-index: 1; margin: 0;
            font-family: var(--script); font-size: 40px; line-height: 1.05; color: var(--surface); }

        .season-card { display: flex; flex-direction: column; justify-content: center; min-width: 0; padding: 4px 0; }
        .season-card h2 { font-size: 44px; line-height: 1.02; }
        @media (min-width: 1280px) { .season-card h2 { font-size: 56px; } }
        @media (min-width: 1440px) { .season-card h2 { font-size: 72px; } }
        .traits { display: flex; flex-wrap: wrap; gap: 8px; margin: 20px 0 0; padding: 0; list-style: none; }
        .traits li { padding: 7px 16px; border: 1px solid var(--line-soft); border-radius: 0; font-size: 13px; color: var(--ink-2); }
        .season-card .lead-in { margin: 0 0 8px; font-size: 15px; line-height: 1.4; color: var(--muted); }
        .season-card .certainty { margin: 18px 0 0; font-size: 15px; line-height: 1.5; color: var(--muted); }
        .season-card .certainty b { font-weight: 400; color: var(--ink-2); }
        .season-card .retake-hint { margin: 6px 0 0; font-size: 13px; line-height: 1.5; color: var(--muted); max-width: 34rem; }
        .season-card .alternative { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 12px; margin: 18px 0 0; padding: 12px 16px;
            max-width: 34rem; background: var(--surface-2); border-left: 2px solid var(--ink-2); }
        .season-card .alternative .label { font-size: 13px; color: var(--muted); }
        .season-card .alternative b { font-family: var(--serif); font-weight: 400; font-size: 20px; color: var(--ink-2); }
        .season-card .alternative .note { flex-basis: 100%; font-size: 13px; line-height: 1.5; color: var(--muted); }
        .band { display: grid; grid-template-columns: repeat(9, minmax(0, 1fr)); gap: 3px; margin: 26px 0 0; max-width: 640px; }
        @media (min-width: 834px) { .band { grid-template-columns: repeat(18, minmax(0, 1fr)); } }
        .band span { display: block; aspect-ratio: 1; }
        .season-card .soft { margin: 18px 0 0; font-size: 13px; line-height: 1.5; color: var(--muted); max-width: 34rem; }
        .season-card .again { align-self: flex-start; margin-top: 22px; }

        /* The four groups, as cards: six tall tiles each */
        .palette-section { margin-top: 56px; }
        .palette-section .lede { margin: 10px 0 0; font-size: 15px; line-height: 1.5; color: var(--muted); }
        .pgroups { display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; margin-top: 24px; }
        @media (min-width: 834px) { .pgroups { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; } }
        @media (min-width: 1280px) { .pgroups { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .pgroup { display: flex; flex-direction: column; min-width: 0; padding: 20px; background: var(--surface-2); }
        .pgroup h3 { font-size: 20px; line-height: 1.3; }
        .pgroup .hint { margin: 6px 0 0; font-size: 13px; line-height: 1.5; color: var(--muted); }
        /* Pushed to the card's foot, so the tiles line up across cards whatever the hint's length */
        .swatches { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 4px; margin-top: auto; padding-top: 16px; }
        .swatch { display: block; aspect-ratio: 3 / 5; border: 1px solid rgba(45, 45, 45, .08); }
        /* "Менше біля обличчя": the tiles are shown, and struck through, so the group cannot be read as a fifth set to wear */
        .pgroup.avoid .swatch { opacity: .55; background-image: linear-gradient(to top right, transparent calc(50% - 1px), rgba(45, 45, 45, .6) calc(50% - 1px), rgba(45, 45, 45, .6) calc(50% + 1px), transparent calc(50% + 1px)) !important; }

        /* The shelf: catalog pieces in the season's colours */
        .shelf { margin-top: 56px; }
        .shelf-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .shelf .note { margin: 10px 0 0; font-size: 14px; line-height: 1.5; color: var(--muted); }
        .shelf .note a, .shelf-all { color: var(--ink-2); font-size: 14px; }
        .shelf-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 12px; margin-top: 24px; }
        @media (min-width: 834px) { .shelf-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 24px 16px; } }
        @media (min-width: 1280px) { .shelf-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .shelf-card { display: flex; flex-direction: column; min-width: 0; }
        .shelf-card .pic { display: block; aspect-ratio: 3 / 4; background: var(--surface-2); overflow: hidden; }
        .shelf-card .pic img { width: 100%; height: 100%; object-fit: contain; }
        .shelf-card .brand { margin-top: 12px; font-size: 12px; letter-spacing: .08em; color: var(--muted); }
        .shelf-card .name { margin: 4px 0 0; font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 14px; line-height: 1.4; color: var(--ink-2);
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .shelf-card .price { margin-top: 6px; font-size: 14px; color: var(--ink-2); }
        .shelf-card .actions { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-top: auto; padding-top: 12px; }
        .shelf-card .actions .dl-btn { min-height: 40px; padding: 8px 6px; font-size: 12px; letter-spacing: .08em; }
        .shelf-empty { margin: 20px 0 0; padding: 24px; background: var(--surface-2); font-size: 14px; line-height: 1.5; color: var(--muted); }

        /* Harmony, tips, signature */
        .sheet-foot { display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; margin-top: 16px; }
        @media (min-width: 834px) { .sheet-foot { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; } }
        .foot-card { min-width: 0; padding: 24px; border: 1px solid var(--line-soft); }
        .foot-card h3 { font-size: 20px; line-height: 1.3; margin-bottom: 12px; }
        .foot-card p { margin: 0; font-size: 15px; line-height: 1.6; color: var(--ink-2); }
        .harmony-dots { display: flex; gap: 8px; margin-top: 20px; }
        .harmony-dots span { width: 40px; height: 40px; border-radius: 50%; border: 1px solid rgba(45, 45, 45, .08); }
        .foot-card .script { margin-top: 14px; font-family: var(--script); font-size: 30px; line-height: 1.1; color: var(--ink-2); }
        .tips { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
        .tips li { display: flex; gap: 12px; align-items: flex-start; font-size: 15px; line-height: 1.5; color: var(--ink-2); }
        .tips svg { flex: none; margin-top: 2px; }
        .sign-card { display: flex; flex-direction: column; background: var(--surface-2); border-color: transparent; }
        .sign-card blockquote { margin: 0; font-family: var(--serif); font-size: 22px; line-height: 1.35; color: var(--ink-2); }
        .sign-card .signature { margin-top: 18px; font-family: var(--script); font-size: 44px; line-height: 1; color: var(--ink-2); }
        .sign-card .consult { margin-top: auto; padding-top: 22px; font-size: 14px; line-height: 1.5; color: var(--muted); }
        .sign-card .consult a { color: var(--ink-2); }

        /* The member's quiz answers, each a way back to its question */
        .answers { list-style: none; margin: 0; padding: 0; }
        .answers a { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; padding: 9px 0;
            border-top: 1px solid var(--line-soft); text-decoration: none; color: var(--ink-2); font-size: 14px; }
        .answers li:last-child a { border-bottom: 1px solid var(--line-soft); }
        .answers a:hover .value, .answers a:focus-visible .value { text-decoration: underline; text-underline-offset: 3px; }
        .answers .label { color: var(--muted); flex: none; }
        .answers .value { text-align: right; }
        .answers .value.empty { color: var(--muted); }
        .answers-note { margin: 14px 0 0 !important; font-size: 13px !important; line-height: 1.5 !important; color: var(--muted) !important; }
        .answers-card .dl-btn { margin-top: 16px; }
@endsection

@section('section')
    <div class="section-rise dl" id="color-section"
         x-data="colorType(@js($profile), @js($pinned), @js($naturalHair), @js([
             'store' => route('membership.account.color.store'),
             'base' => url('/account/color'),
         ]))"
         x-init="start()">

        {{-- Intake ---------------------------------------------------------- --}}
        <div id="color-intake" x-show="! sheet" @if (($profile['status'] ?? null) === 'ok') x-cloak @endif>
            <div class="dl-head">
                <div>
                    <div class="eyebrow">Кольоротип</div>
                    <h1>Твої кольори</h1>
                    <p class="lede">Одне фото при денному світлі — і ти знаєш, які відтінки підсвічують обличчя, а які гасять його.</p>
                </div>
            </div>

            <div class="color-grid">
                {{-- Photo -------------------------------------------------- --}}
                <div class="panel" id="color-photo-panel">
                    <p class="panel-label">Фото обличчя</p>
                    <div class="frame-3x4" :class="{ 'is-over': dragOver }"
                         x-on:dragover.prevent="dragOver = true" x-on:dragleave.prevent="dragOver = false"
                         x-on:drop.prevent="dropPhoto($event)">
                        <template x-if="photoUrl"><img :src="photoUrl" alt="Ваше фото"></template>
                        <button type="button" class="retake" x-show="photoUrl" x-on:click="$refs.photo.click()">Інше фото</button>
                        <div class="dropzone" x-show="! photoUrl" id="color-dropzone">
                            <span class="mark"><svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><circle cx="9" cy="6.2" r="3" stroke="#2D2D2D" stroke-width="1.2"/><path d="M3.5 15c.6-3 2.9-4.6 5.5-4.6S13.9 12 14.5 15" stroke="#2D2D2D" stroke-width="1.2" stroke-linecap="round"/></svg></span>
                            <p class="dz-title">Додати фото обличчя</p>
                            <p class="dz-hint">Перетягнути файл або вибрати на пристрої. Обличчя й плечі, погляд у камеру.</p>
                            <button type="button" class="dl-btn dl-btn-dark dz-btn" x-on:click="$refs.photo.click()">Обрати фото</button>
                            <p class="dz-formats">JPG, PNG, WebP або HEIC — оригінал із галереї</p>
                        </div>
                    </div>
                    <input type="file" accept="image/*,.heic,.heif" hidden x-ref="photo" x-on:change="pickPhoto($event)">

                    {{-- Each rule is a reason the engine has refused a real photo for. --}}
                    <ul class="shot-rules" id="color-rules">
                        <li>Денне світло, обличчям до вікна. Без спалаху й не під прямим сонцем.</li>
                        <li>Без макіяжу, фільтрів і ретуші — знімок як є.</li>
                        <li>Волосся прибрати з чола та щік, окуляри зняти.</li>
                        <li>Оригінал із галереї — не скриншот і не фото, переслане в месенджері: вони стиснуті й пересвічені, і колір шкіри губиться.</li>
                    </ul>

                    <fieldset class="hair-q" id="color-hair">
                        <legend>Колір волосся зараз натуральний?</legend>
                        <div class="answers">
                            <button type="button" :class="{ active: naturalHair === '1' }" x-on:click="naturalHair = naturalHair === '1' ? '' : '1'">Так</button>
                            <button type="button" :class="{ active: naturalHair === '0' }" x-on:click="naturalHair = naturalHair === '0' ? '' : '0'">Ні, фарбоване</button>
                        </div>
                        <p class="why">Фарбоване волосся не враховується: сезон визначають шкіра й очі.</p>
                        {{-- Asked only of dyed hair. A note for the stylist: the colour of the dye is
                             still left out, and so is this answer, when the season is decided. --}}
                        <div class="previous" id="color-previous-hair" x-show="naturalHair === '0'" x-cloak>
                            <p class="prev-q">Який колір волосся був раніше, натуральний?</p>
                            <div class="answers">
                                <template x-for="o in [['light', 'Світлий'], ['medium', 'Середній'], ['dark', 'Темний'], ['red', 'Рудий'], ['grey', 'Сивий']]" :key="o[0]">
                                    <button type="button" :class="{ active: previousHair === o[0] }" x-text="o[1]" x-on:click="previousHair = previousHair === o[0] ? '' : o[0]"></button>
                                </template>
                            </div>
                        </div>
                    </fieldset>

                    @if (config('consent.required'))
                    <label class="consent" id="color-consent">
                        <input type="checkbox" x-model="consent">
                        <span>Погоджуюсь, що фото мого обличчя буде проаналізовано, щоб визначити кольоротип. Фото видаляється протягом {{ $photoHours }} годин, результат залишається в кабінеті.
                            <a href="{{ url('legal/privacy-policy') }}" target="_blank" rel="noopener">Політика конфіденційності</a></span>
                    </label>
                    @endif

                    <button type="button" class="dl-btn dl-btn-dark dl-btn-block color-submit" id="color-submit"
                            :disabled="busy || ! file || ! consent" x-on:click="submit()">
                        <span x-text="busy ? 'Надсилаємо…' : (profile && ! profile.pending ? 'Визначити ще раз' : 'Визначити кольоротип')"></span>
                    </button>
                    <p class="form-error" x-show="formError" x-cloak x-text="formError"></p>
                </div>

                {{-- Result ------------------------------------------------- --}}
                <div class="panel" id="color-result-panel">
                    <p class="panel-label">Результат</p>
                    <div class="result-box">
                        {{-- Nothing yet --}}
                        <div class="state centered" x-show="! profile" id="color-empty">
                            <span class="mark"><svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><circle cx="6.5" cy="8" r="4" stroke="#2D2D2D" stroke-width="1.2"/><circle cx="11.5" cy="8" r="4" stroke="#2D2D2D" stroke-width="1.2"/><circle cx="9" cy="12" r="4" stroke="#2D2D2D" stroke-width="1.2"/></svg></span>
                            <p class="title">Тут з'явиться твій сезон</p>
                            <p class="hint">Додай фото, постав галочку згоди — і за кілька секунд побачиш, який ти сезон і які кольори твої.</p>
                        </div>

                        {{-- Working --}}
                        <div class="state centered" x-show="profile && profile.status === 'pending'" x-cloak id="color-pending">
                            <span class="working-bar" aria-hidden="true"></span>
                            <p class="title">Дивимось на фото</p>
                            <p class="hint">Це займе кілька секунд. Сторінка оновиться сама.</p>
                        </div>

                        {{-- The engine looked and said what to change --}}
                        <div class="state centered" x-show="profile && profile.status === 'rejected'" x-cloak id="color-rejected">
                            <p class="title">Потрібне інше фото</p>
                            <p class="hint" x-text="profile && profile.reject_message"></p>
                            <button type="button" class="dl-btn dl-btn-outline retry" x-on:click="$refs.photo.click()">Обрати інше фото</button>
                            <button type="button" class="dl-btn dl-btn-outline retry" x-show="pinned" x-on:click="backToPinned()">Повернутися до мого сезону</button>
                        </div>

                        {{-- Measured, and still no season: a real outcome --}}
                        <div class="state centered" x-show="profile && profile.status === 'unplaced'" x-cloak id="color-unplaced">
                            <p class="title">Сезон не визначився</p>
                            <p class="hint">За цим фото не вдалося виміряти достатньо. Спробуй інше: денне світло, обличчя ближче, волосся прибране.</p>
                            <button type="button" class="dl-btn dl-btn-outline retry" x-on:click="$refs.photo.click()">Обрати інше фото</button>
                            <button type="button" class="dl-btn dl-btn-outline retry" x-show="pinned" x-on:click="backToPinned()">Повернутися до мого сезону</button>
                        </div>

                        {{-- Our side broke: says nothing about the photo --}}
                        <div class="state centered" x-show="profile && profile.status === 'failed'" x-cloak id="color-failed">
                            <p class="title">Не вийшло з нашого боку</p>
                            <p class="hint">Фото тут ні до чого. Спробуй ще раз за хвилину, і якщо не допоможе — напиши нам.</p>
                            <button type="button" class="dl-btn dl-btn-outline retry" :disabled="! file || ! consent" x-on:click="submit()">Спробувати ще раз</button>
                            <button type="button" class="dl-btn dl-btn-outline retry" x-show="pinned" x-on:click="backToPinned()">Повернутися до мого сезону</button>
                        </div>

                        {{-- Retaking from the sheet: until a photo is sent, the season is one click away --}}
                        <div class="state centered" x-show="retaking" x-cloak id="color-retaking">
                            <p class="title">Нове визначення</p>
                            <p class="hint">Додай нове фото — результат з'явиться тут і замінить попередній.</p>
                            <button type="button" class="dl-btn dl-btn-outline retry" x-on:click="retaking = false">Повернутися до результату</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Sheet ------------------------------------------------------------ --}}
        <div class="sheet" id="color-sheet" x-show="sheet" x-cloak>
            <section class="sheet-hero" id="color-season">
                <div class="portrait" id="color-portrait">
                    <template x-if="storedPhoto"><img :src="storedPhoto" alt="Твоє фото"></template>
                    {{-- Once the photo is gone, the portrait is the palette itself --}}
                    <div class="mosaic" x-show="! storedPhoto" aria-hidden="true">
                        <template x-for="color in wearable" :key="'m' + color.hex">
                            <span :style="{ background: color.hex }"></span>
                        </template>
                    </div>
                    <p class="caption">Твоя природна краса</p>
                </div>

                <div class="season-card">
                    <p class="lead-in">Твій найбільш ймовірний кольоротип —</p>
                    {{-- Server-rendered first, so the season is on the page before Alpine wakes. --}}
                    <h2 x-text="profile && profile.season ? profile.season.name : ''">{{ $profile['season']['name'] ?? '' }}</h2>
                    <ul class="traits" id="color-traits">
                        <template x-for="trait in (profile && profile.season ? profile.season.traits : [])" :key="trait">
                            <li x-text="trait"></li>
                        </template>
                    </ul>
                    {{-- How sure, in a word; when low, the way to a better reading --}}
                    <p class="certainty" id="color-confidence" x-show="profile && profile.confidence_level">
                        Впевненість аналізу: <b x-text="confidenceWord"></b>
                    </p>
                    <p class="retake-hint" id="color-retake-hint" x-show="profile && profile.confidence_level === 'low'">
                        Для точнішого результату завантаж інше фото — оригінал із галереї, при денному світлі.
                    </p>
                    <div class="alternative" id="color-alternative" x-show="profile && profile.alternative">
                        <span class="label">Найближча альтернатива</span>
                        <b x-text="profile && profile.alternative ? profile.alternative.name : ''"></b>
                        <span class="note" x-show="profile && profile.confidence_level === 'low'">Сезони близькі — варто приміряти кольори обох.</span>
                    </div>
                    {{-- Every colour to wear, as one band: neutrals, base, accents --}}
                    <div class="band" aria-label="Твоя палітра">
                        <template x-for="color in wearable" :key="color.hex">
                            <span :style="{ background: color.hex }"></span>
                        </template>
                    </div>
                    <p class="soft">AI-аналіз визначив найбільш ймовірний сезон за фото. Стилістка може провести персональний аналіз і поглибити рекомендації щодо відтінків, контрастності та поєднань.</p>
                    <button type="button" class="dl-btn dl-btn-outline again" id="color-again" x-on:click="retake()">Визначити ще раз</button>
                </div>
            </section>

            {{-- Palette -------------------------------------------------------- --}}
            <section class="palette-section" id="color-palette">
                <div class="dl-head">
                    <div>
                        <div class="eyebrow">Палітра</div>
                        <h2>Що носити, а що залишити</h2>
                    </div>
                </div>
                <div class="pgroups">
                    <template x-for="group in (profile ? profile.palette : [])" :key="group.name">
                        <div class="pgroup" :class="{ avoid: group.avoid }" :id="'palette-' + group.name">
                            <h3 x-text="group.label"></h3>
                            <p class="hint" x-text="group.hint"></p>
                            <div class="swatches">
                                <template x-for="color in group.colors" :key="color.hex">
                                    <span class="swatch" :style="{ background: color.hex }"></span>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="shelf" id="color-shelf">
                    <div class="shelf-head">
                        <div>
                            <div class="eyebrow">Каталог</div>
                            <h2>Речі у твоїх кольорах</h2>
                        </div>
                        <a class="shelf-all" x-show="profile && profile.products.length" href="{{ route('membership.account.dashboard', ['mine' => 1]) }}#catalog-grid">Увесь каталог у моїх кольорах</a>
                    </div>
                    <p class="note" x-show="profile && profile.products.length">
                        <span x-text="shelfNote"></span>
                        <a href="{{ route('membership.account.onboarding') }}">Змінити відповіді анкети</a>
                    </p>
                    <div class="shelf-grid">
                        <template x-for="product in (profile ? profile.products : [])" :key="product.id">
                            <article class="shelf-card" :id="'shelf-' + product.id">
                                <a class="pic" :href="product.try_url"><img :src="product.image" :alt="product.name" loading="lazy"></a>
                                <div class="brand" x-text="product.brand"></div>
                                <h3 class="name" x-text="product.name"></h3>
                                <div class="price" x-text="product.price"></div>
                                <div class="actions">
                                    <a class="dl-btn dl-btn-dark" :href="product.buy_url" target="_blank" rel="noopener">Купити</a>
                                    <a class="dl-btn dl-btn-outline" :href="product.try_url">Приміряти</a>
                                </div>
                            </article>
                        </template>
                    </div>
                    <p class="shelf-empty" id="color-shelf-empty" x-show="profile && ! profile.products.length">
                        Поки в каталозі немає речей у твоїх кольорах. Каталог оновлюється щодня — зазирни трохи згодом.
                    </p>
                </div>

                <div class="sheet-foot">
                    <div class="foot-card" id="color-harmony">
                        <h3>Гармонія</h3>
                        <p x-text="profile && profile.season ? profile.season.description : ''">{{ $profile['season']['description'] ?? '' }}</p>
                        <div class="harmony-dots" aria-hidden="true">
                            <template x-for="color in harmony" :key="'h' + color.hex">
                                <span :style="{ background: color.hex }"></span>
                            </template>
                        </div>
                        <p class="script">Гармонія в кожній деталі</p>
                    </div>

                    <div class="foot-card" id="color-tips" x-show="profile && profile.season && profile.season.tips.length">
                        <h3>Поради</h3>
                        <ul class="tips">
                            <template x-for="tip in (profile && profile.season ? profile.season.tips : [])" :key="tip">
                                <li>
                                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><circle cx="9" cy="9" r="8" stroke="currentColor" stroke-width="1"/><path d="M5.6 9.2l2.3 2.3 4.5-4.8" stroke="currentColor" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <span x-text="tip"></span>
                                </li>
                            </template>
                        </ul>
                    </div>

                    <div class="foot-card answers-card" id="color-answers">
                        <h3>Твої відповіді</h3>
                        <ul class="answers">
                            @foreach ($quizSummary as $row)
                                <li>
                                    <a href="{{ route('membership.account.onboarding', ['step' => $row['key']]) }}" id="answer-{{ $row['key'] }}">
                                        <span class="label">{{ $row['short'] }}</span>
                                        <span class="value {{ $row['answer'] === null ? 'empty' : '' }}">{{ $row['answer'] ?? 'Не вказано' }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        <p class="answers-note">Відповіді про кольори враховуються, коли сезон визначається за фото — додай свіже селфі в кінці анкети. Категорії й бюджет змінюють підбір речей одразу.</p>
                        <a class="dl-btn dl-btn-outline" href="{{ route('membership.account.onboarding') }}">Змінити відповіді</a>
                    </div>

                    <div class="foot-card sign-card" id="color-signature">
                        <blockquote>Коли колір твій, першим помічають тебе, а не одяг.</blockquote>
                        <p class="signature">Atelier Fit</p>
                        <p class="consult">
                            Палітра підібрана під твій сезон автоматично. Хочеш, щоб її зібрали під тебе руками —
                            <a href="{{ route('membership.account.vip', ['plan' => $plan]) }}">запиши консультацію зі стилісткою</a>.
                        </p>
                    </div>
                </div>
            </section>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        function colorType(profile, pinned, naturalHair, routes) {
            return {
                profile: profile,
                // The member's season when the profile on screen is a later
                // attempt that has not (yet) placed one.
                pinned: pinned,
                // The photo as chosen on this device, or the stored one while it exists.
                photoUrl: null,
                file: null,
                dragOver: false,
                // Prefilled from the style quiz; answering here updates it.
                naturalHair: naturalHair,
                // Natural colour before the dye; sent only with 'dyed'.
                previousHair: @js($previousHair),
                // No box while consent is switched off (config/consent.php).
                consent: @json(! config('consent.required')),
                busy: false,
                formError: '',
                timer: null,
                // "Визначити ще раз" from the sheet: the intake comes back, the season stays.
                retaking: false,

                start() {
                    // A photo that gave no season stays in the frame, to be swapped.
                    if (this.profile && this.profile.status !== 'ok') this.photoUrl = this.storedPhoto;
                    this.poll();
                },

                // Once there is a season the sheet is the page, unless it is being retaken.
                get sheet() {
                    return this.profile?.status === 'ok' && ! this.retaking;
                },

                // The stored photo, while it still exists and a browser can draw it.
                get storedPhoto() {
                    return this.profile?.photo?.available ? `${routes.base}/${this.profile.id}/photo` : null;
                },

                // Neutrals, base and accents in one band — everything to wear.
                get wearable() {
                    return (this.profile?.palette ?? [])
                        .filter((group) => ! group.avoid)
                        .flatMap((group) => group.colors);
                },

                // Five of the everyday colours, as the harmony's dots.
                get harmony() {
                    const base = (this.profile?.palette ?? []).find((group) => group.name === 'base');
                    return (base ? base.colors : this.wearable).slice(0, 5);
                },

                // The confidence level, as the page says it.
                get confidenceWord() {
                    return { low: 'низька', medium: 'середня', high: 'висока' }[this.profile?.confidence_level] ?? '';
                },

                // Says which of the quiz's answers the shelf honours: a filter
                // that would have left it empty is dropped (ColorRecommendations).
                get shelfNote() {
                    const by = this.profile?.products_filtered_by ?? [];
                    if (by.includes('categories') && by.includes('budget')) return 'Під твій сезон, а також категорії та бюджет з анкети.';
                    if (by.includes('categories')) return 'Під твій сезон і категорії з анкети.';
                    if (by.includes('budget')) return 'Під твій сезон і бюджет з анкети.';
                    return 'Під твій сезон.';
                },

                retake() {
                    this.pinned = this.profile;
                    this.retaking = true;
                    this.photoUrl = null;
                    this.file = null;
                    this.formError = '';
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                },

                backToPinned() {
                    this.profile = this.pinned;
                    this.pinned = null;
                    this.retaking = false;
                    this.photoUrl = null;
                    this.file = null;
                    clearTimeout(this.timer);
                },

                pickPhoto(event) {
                    this.usePhoto(event.target.files && event.target.files[0]);
                },
                dropPhoto(event) {
                    this.dragOver = false;
                    this.usePhoto(event.dataTransfer && event.dataTransfer.files[0]);
                },
                usePhoto(file) {
                    if (! file) return;
                    this.file = file;
                    this.formError = '';
                    if (this.photoUrl && this.photoUrl.startsWith('blob:')) URL.revokeObjectURL(this.photoUrl);
                    // A phone's HEIC cannot be drawn by the browser; the frame
                    // then shows the name of the file instead of a broken image.
                    this.photoUrl = /image\/(jpeg|png|webp)/.test(file.type) ? URL.createObjectURL(file) : null;
                },

                async submit() {
                    if (! this.file || ! this.consent || this.busy) return;

                    this.busy = true;
                    this.formError = '';

                    const body = new FormData();
                    body.append('photo', this.file);
                    body.append('consent', '1');
                    if (this.naturalHair !== '') body.append('natural_hair', this.naturalHair);
                    if (this.naturalHair === '0' && this.previousHair !== '') body.append('previous_hair', this.previousHair);

                    try {
                        const response = await fetch(routes.store, {
                            method: 'POST',
                            body,
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.token() },
                        });
                        const payload = await response.json();
                        if (! response.ok) {
                            const errors = payload.errors ? Object.values(payload.errors).flat() : [];
                            this.formError = errors[0] ?? payload.message ?? 'Не вдалося надіслати фото.';
                            return;
                        }
                        this.profile = payload;
                        this.retaking = false;
                        this.poll();
                    } catch (error) {
                        this.formError = 'Немає зв\'язку з сервером. Спробуй ще раз.';
                    } finally {
                        this.busy = false;
                    }
                },

                poll() {
                    clearTimeout(this.timer);
                    if (! this.profile?.pending) return;

                    this.timer = setTimeout(async () => {
                        try {
                            const response = await fetch(`${routes.base}/${this.profile.id}`, {
                                headers: { 'Accept': 'application/json' },
                            });
                            if (response.ok) this.profile = await response.json();
                        } catch (error) { /* try again on the next tick */ }
                        this.poll();
                    }, 2500);
                },

                token() {
                    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                },
            };
        }
    </script>
@endsection

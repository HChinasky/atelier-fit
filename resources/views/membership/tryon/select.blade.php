@extends('membership.tryon.layout')

@section('title', __('tryon_steps.title_select'))
{{-- One viewport, no page scroll, as the phone's dashboard (html.m-fit). --}}
@section('html-class', 'ty-fit')

@push('styles')
    <style>
        /* Step 2 · the design hand-over, fitted to the viewport's
           height the way the phone's dashboard is: the done strip, the search,
           the chips and the hint keep their height; the grid takes what is
           left and scrolls inside, under the sticky button. */
        .t2 > * { flex-shrink: 0; }

        /* Step 1 done strip + step 2 pill */
        .t2-row { display: flex; align-items: center; gap: 12px; padding: 18px 20px 0 20px; }
        .t2-done { display: flex; align-items: center; gap: 10px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--pill);
            padding: 5px 14px 5px 5px; flex-shrink: 0; }
        .t2-avatar { width: 40px; height: 40px; border-radius: 20px; overflow: hidden; position: relative; flex-shrink: 0; background: var(--surface); }
        /* The member's own photo as an avatar: a face crop, not a piece. */
        .t2-avatar img { width: 100%; height: 100%; object-fit: cover; object-position: center top; display: block; }
        .t2-done-text { display: flex; flex-direction: column; gap: 1px; }
        .t2-done-line { display: flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 600; }
        .t2-change { font-size: 10.5px; color: var(--muted); text-decoration: underline; text-underline-offset: 2px; }
        .t2-pill { margin: 0; display: inline-flex; align-items: center; gap: 8px; background: var(--surface-2); border-radius: var(--pill); padding: 6px 14px 6px 6px; flex: 1; min-width: 0; }
        .t2-pill-text { font-family: var(--display); font-weight: 500; font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Search + brands */
        .t2-search-row { display: flex; gap: 8px; padding: 14px 20px 0 20px; position: relative; }
        .t2-search { flex: 1; min-width: 0; display: flex; align-items: center; gap: 10px; height: 46px; border-radius: 23px; background: var(--surface);
            border: 1px solid var(--border); padding: 0 16px; }
        .t2-search input { flex: 1; min-width: 0; border: 0; background: transparent; font-family: inherit; font-size: 13px; color: var(--ink); outline: none; padding: 0; }
        .t2-search input::placeholder { color: var(--muted); }
        .t2-search input::-webkit-search-cancel-button { display: none; }
        .t2-brands { height: 46px; border-radius: 23px; background: var(--surface); border: 1px solid var(--border); padding: 0 14px 0 16px;
            display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 500; color: var(--ink); cursor: pointer; flex-shrink: 0; max-width: 160px; }
        .t2-brands span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .t2-brand-list { position: absolute; inset-inline-end: 20px; top: 66px; z-index: 5; min-width: 180px; max-height: 280px; overflow-y: auto; margin: 0; padding: 6px;
            list-style: none; background: var(--surface); border: 1px solid var(--border); border-radius: 18px; box-shadow: 0 12px 28px -16px rgba(43, 35, 29, .45); }
        .t2-brand-list button { width: 100%; padding: 10px 12px; border: 0; border-radius: 12px; background: transparent; text-align: start; font-size: 13px; color: var(--ink); cursor: pointer; }
        .t2-brand-list button:hover, .t2-brand-list button[aria-pressed="true"] { background: var(--surface-2); }

        /* Category chips: one row, scrolled sideways */
        .t2-chips { display: flex; gap: 6px; padding: 12px 0 0 20px; overflow-x: auto; overflow-y: hidden; scrollbar-width: none;
            -webkit-overflow-scrolling: touch; overscroll-behavior-x: contain; }
        .t2-chips::after { content: ''; flex: none; width: 14px; }
        .t2-chips::-webkit-scrollbar { display: none; }
        .t2-chip { flex: none; height: 34px; border-radius: 17px; border: 0; background: var(--surface-2); color: var(--ink); font-size: 12.5px; font-weight: 500;
            padding: 0 14px; white-space: nowrap; cursor: pointer; }
        .t2-chip[aria-pressed="true"] { background: var(--ink); color: var(--surface); font-weight: 600; }

        /* Limit / count line */
        .t2-hint { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px 0 20px; font-size: 12px; }
        .t2-hint-limit { color: var(--muted); }
        .t2-hint-count { font-weight: 600; color: var(--ink); }

        /* Product grid: two columns, the block that gives, scrolled inside
           under the CTA; room at its end for the sticky CTA (28 + 54 + 10)
           above the tab bar. */
        .t2-grid { flex: 1 1 auto; min-height: 0; overflow-y: auto; overscroll-behavior: contain; scrollbar-width: none;
            display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; padding: 10px 20px 104px 20px; align-content: start; }
        .t2-grid::-webkit-scrollbar { display: none; }
        .t2-card { background: var(--surface); border: 1px solid var(--border); border-radius: 20px; padding: 8px 8px 10px 8px;
            display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .t2-card.is-picked { border: 1.5px solid var(--ink); box-shadow: 0 0 0 3px var(--surface-2); }
        .t2-card-pic { position: relative; height: 176px; border-radius: 14px; overflow: hidden; background: var(--surface); }
        /* A piece is always shown whole (AppearanceTest). */
        .t2-card-pic img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .t2-badge { position: absolute; top: 8px; inset-inline-start: 8px; height: 24px; border-radius: 12px; background: var(--ink); color: var(--surface); font-size: 10.5px; font-weight: 600;
            display: flex; align-items: center; gap: 4px; padding: 0 9px 0 7px; }
        .t2-like { position: absolute; top: 8px; inset-inline-end: 8px; width: 30px; height: 30px; border-radius: 15px; border: 0; background: var(--surface);
            display: flex; align-items: center; justify-content: center; padding: 0; cursor: pointer; }
        .t2-like[aria-pressed="true"] svg { fill: var(--ink); }
        .t2-brand { font-size: 10px; letter-spacing: 0.08em; color: var(--muted); margin-top: 4px; text-transform: uppercase;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .t2-name { font-size: 12.5px; line-height: 1.3; height: 32px; overflow: hidden; }
        .t2-price { font-family: var(--display); font-weight: 600; font-size: 14px; }
        .t2-try { margin-top: 4px; height: 36px; border-radius: 18px; border: 0; background: var(--surface-2); color: var(--ink); font-size: 12px; font-weight: 600;
            display: flex; align-items: center; justify-content: center; gap: 6px; cursor: pointer; }
        .t2-try[aria-pressed="true"] { background: var(--ink); color: var(--surface); }
        .t2-empty { grid-column: 1 / -1; margin: 4px 0 0; font-size: 13px; color: var(--muted); }

        /* Short phones (375×667), as on the dashboard: tighter rows, a
           smaller picture, so a card and the next one's top stay in view. */
        @media (max-height: 700px) {
            .t2-row { padding-top: 12px; }
            .t2-search-row { padding-top: 10px; }
            .t2-search, .t2-brands { height: 42px; }
            .t2-brand-list { top: 58px; }
            .t2-chips { padding-top: 8px; }
            .t2-hint { padding-top: 10px; }
            .t2-grid { padding-top: 8px; }
            .t2-card-pic { height: 150px; }
        }

        /* Sticky CTA over the grid, right above the tab bar (bottom: 78px in the reference) */
        .t2-cta { position: fixed; left: 50%; transform: translateX(-50%); width: 100%; max-width: 390px; z-index: 9;
            bottom: calc(var(--tabs-h) + 1px + env(safe-area-inset-bottom)); padding: 28px 20px 10px 20px;
            background: linear-gradient(180deg, rgba(244, 239, 231, 0) 0%, var(--surface) 40%); pointer-events: none; }
        .t2-go { pointer-events: auto; width: 100%; height: 54px; border-radius: 27px; border: 0; background: var(--ink); color: var(--surface);
            font-family: var(--display); font-weight: 500; font-size: 18px; display: flex; align-items: center; justify-content: center; gap: 10px; cursor: pointer; }
        .t2-go:disabled { opacity: .45; cursor: not-allowed; }
        .t2-go-count { font-family: var(--body); font-size: 12px; font-weight: 700; background: rgba(255, 255, 255, 0.18); border-radius: var(--pill); padding: 3px 9px; }

        /* Toast above the CTA */
        .t2-toast { position: fixed; left: 50%; transform: translateX(-50%); z-index: 11; width: max-content; max-width: 350px;
            bottom: calc(var(--tabs-h) + 92px + 8px + env(safe-area-inset-bottom)); background: var(--ink); color: var(--surface);
            font-size: 12.5px; font-weight: 600; line-height: 1.35; border-radius: 18px; padding: 10px 16px; text-align: center; }
        .t2-toast a { color: var(--surface); margin-inline-start: 4px; }

        /* The finished look, over the whole screen */
        .t2-result { position: fixed; inset: 0; z-index: 20; background: var(--surface); }
        .t2-result-inner { max-width: 390px; height: 100%; margin: 0 auto; display: flex; flex-direction: column; }
        .t2-result-head { display: flex; align-items: center; justify-content: space-between; padding: 22px 20px 0 20px; }
        .t2-result-title { margin: 0; font-family: var(--display); font-weight: 500; font-size: 24px; }
        .t2-result-close { cursor: pointer; }
        /* A look is always shown whole (AppearanceTest). */
        .t2-result img { flex: 1; min-height: 0; width: 100%; object-fit: contain; display: block; padding: 18px 20px 24px; }
    </style>
@endpush

@section('content')
    @php($hanger = 'M12 4a2 2 0 1 1 2 2c-1 0-2 .8-2 2v1.2M12 9l9 6.2a1 1 0 0 1-.6 1.8H3.6a1 1 0 0 1-.6-1.8z')
    @php($heart = 'M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z')
    @php($check = 'M5 12l5 5 9-10')
    <main class="t2" x-data="tryOnSelect()">
        {{-- Step 1 done + step 2 --}}
        <div class="t2-row">
            <div class="t2-done">
                <div class="t2-avatar">
                    <img id="tryon-photo" src="{{ route('membership.account.try-on.photo') }}" alt="{{ __('tryon_steps.photo_alt') }}">
                </div>
                <div class="t2-done-text">
                    <div class="t2-done-line">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#2D2D2D" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $check }}"></path></svg>
                        {{ __('tryon_steps.photo_added') }}
                    </div>
                    <a class="t2-change" href="{{ route('membership.account.try-on.step1') }}">{{ __('tryon_steps.change') }}</a>
                </div>
            </div>
            <p class="t2-pill"><span class="ty-num">2</span><span class="t2-pill-text">{{ __('tryon_steps.step2') }}</span></p>
        </div>

        {{-- Search + brands --}}
        <div class="t2-search-row" x-on:click.outside="brandsOpen = false" x-on:keydown.escape="brandsOpen = false">
            <label class="t2-search">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6B6B6B" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="M20 20l-4-4"></path></svg>
                <input type="search" id="tryon-search" x-model.debounce.150ms="search" placeholder="{{ __('tryon_steps.search') }}" aria-label="{{ __('tryon_steps.search_label') }}">
            </label>
            <button type="button" class="t2-brands" id="tryon-brands" :aria-expanded="brandsOpen" aria-haspopup="true" x-on:click="brandsOpen = ! brandsOpen">
                <span x-text="brand || t.all_brands">{{ __('tryon_steps.all_brands') }}</span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2D2D2D" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"></path></svg>
            </button>
            <ul class="t2-brand-list" x-show="brandsOpen" x-cloak x-transition.opacity>
                <li><button type="button" :aria-pressed="brand === ''" x-on:click="brand = ''; brandsOpen = false">{{ __('tryon_steps.all_brands') }}</button></li>
                @foreach ($brands as $brandName)
                    <li><button type="button" :aria-pressed="brand === @js($brandName)" x-on:click="brand = @js($brandName); brandsOpen = false">{{ $brandName }}</button></li>
                @endforeach
            </ul>
        </div>

        {{-- Category chips, fixed by the spec: "Для тебе" first and on by default --}}
        <div class="t2-chips" id="tryon-chips" role="group"
             x-on:wheel="if (Math.abs($event.deltaY) > Math.abs($event.deltaX) && $el.scrollWidth > $el.clientWidth) { $el.scrollLeft += $event.deltaY; $event.preventDefault() }">
            <button type="button" class="t2-chip" :aria-pressed="chip === 'for-you'" x-on:click="chip = 'for-you'">{{ __('tryon_steps.for_you') }}</button>
            <button type="button" class="t2-chip" :aria-pressed="chip === ''" x-on:click="chip = ''">{{ __('tryon_steps.all') }}</button>
            @foreach (\App\Services\Catalog\TryOnChips::KEYS as $key)
                <button type="button" class="t2-chip" :aria-pressed="chip === @js($key)" x-on:click="chip = @js($key)">{{ __('tryon_steps.chips.'.$key) }}</button>
            @endforeach
        </div>

        <div class="t2-hint">
            <span class="t2-hint-limit">{{ __('tryon_steps.limit', ['count' => $maxItems]) }}</span>
            <span class="t2-hint-count" id="tryon-picked-count" x-text="t.picked_count.replace(':count', picked.length)">{{ __('tryon_steps.picked_count', ['count' => 0]) }}</span>
        </div>

        {{-- Cards: the verified catalog, names as the catalog holds them --}}
        <div class="t2-grid" id="tryon-grid">
            @foreach ($products as $product)
                <article class="t2-card" :class="{ 'is-picked': isPicked({{ $product->id }}) }" x-show="matches({{ $product->id }})">
                    <div class="t2-card-pic">
                        <img src="{{ $product->image_flat_url }}" alt="{{ $product->brand_name }} {{ $product->product_name }}" loading="lazy">
                        <div class="t2-badge" x-show="isPicked({{ $product->id }})" x-cloak>
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $check }}"></path></svg>
                            {{ __('tryon_steps.picked') }}
                        </div>
                        <button type="button" class="t2-like" :aria-pressed="isLiked({{ $product->id }})"
                                :aria-label="isLiked({{ $product->id }}) ? t.unlike : t.like" aria-label="{{ __('tryon_steps.like') }}"
                                x-on:click="toggleLike({{ $product->id }})">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2D2D2D" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $heart }}"></path></svg>
                        </button>
                    </div>
                    <div class="t2-brand">{{ $product->brand_name }}</div>
                    <div class="t2-name">{{ $product->product_name }}</div>
                    <div class="t2-price">AED {{ rtrim(rtrim(number_format((float) $product->price_aed, 2, '.', ''), '0'), '.') }}</div>
                    <button type="button" class="t2-try" :aria-pressed="isPicked({{ $product->id }})" x-on:click="togglePick({{ $product->id }})">
                        <svg x-show="! isPicked({{ $product->id }})" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2D2D2D" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $hanger }}"></path></svg>
                        <svg x-show="isPicked({{ $product->id }})" x-cloak width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $check }}"></path></svg>
                        <span x-text="isPicked({{ $product->id }}) ? t.in_tryon : t.try">{{ __('tryon_steps.try') }}</span>
                    </button>
                </article>
            @endforeach
            <p class="t2-empty" id="tryon-empty" x-show="! anyVisible()" x-cloak>{{ __('tryon_steps.empty') }}</p>
        </div>

        {{-- Sticky CTA --}}
        <div class="t2-cta">
            <button type="button" class="t2-go" id="tryon-generate" :disabled="! canGenerate()" x-on:click="generate()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="#FFFFFF" aria-hidden="true"><path d="M10 3l1.8 5.2L17 10l-5.2 1.8L10 17l-1.8-5.2L3 10l5.2-1.8zM18 14l.9 2.6L21.5 17.5l-2.6.9L18 21l-.9-2.6L14.5 17.5l2.6-.9z"></path></svg>
                <span x-text="busy ? t.busy : t.generate">{{ __('tryon_steps.generate') }}</span>
                <span class="t2-go-count" x-show="! busy" x-text="itemsLabel(picked.length)">{{ str_replace(':count', 0, __('tryon_steps.items.many')) }}</span>
            </button>
        </div>

        <div class="t2-toast" id="tryon-toast" role="status" aria-live="polite" x-show="toast" x-cloak x-transition.opacity>
            <span x-text="toast"></span><a href="{{ $tryonRoutes['pricing'] }}" x-show="paywall">{{ __('tryon_steps.paywall_link') }}</a>
        </div>

        <div class="t2-result" id="tryon-result" x-show="result" x-cloak role="dialog" aria-modal="true" aria-labelledby="tryon-result-title"
             x-on:keydown.escape.window="result = null">
            <div class="t2-result-inner">
                <div class="t2-result-head">
                    <h2 class="t2-result-title" id="tryon-result-title">{{ __('tryon_steps.done') }}</h2>
                    <button type="button" class="ty-me t2-result-close" aria-label="{{ __('tryon_steps.close') }}" x-on:click="result = null">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2D2D2D" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"></path></svg>
                    </button>
                </div>
                <img :src="result" alt="{{ __('tryon_steps.result_alt') }}">
            </div>
        </div>
    </main>
@endsection

@push('scripts')
    <script>
        function tryOnSelect() {
            return {
                t: @js(__('tryon_steps')),
                locale: @json($locale),
                // The member's owned lead and its token: /generate debits their credits.
                memberLead: @json($memberLead),
                // The photo uploaded on step 1, kept in the session.
                photoKey: @json($photo['key'] ?? null),
                consentId: @json($photo['consent_id'] ?? null),
                routes: @json($tryonRoutes),
                forYou: @json(array_values($forYouIds)),
                liked: @json(array_values($likedIds)),
                maxItems: @json($maxItems),
                catalog: @json($catalog),
                byId: {},

                search: '',
                brand: '',
                brandsOpen: false,
                chip: 'for-you',
                picked: [],
                toast: '',
                toastTimer: null,
                paywall: false,

                // idle | processing | done | failed
                genState: 'idle',
                statusUrl: null,
                result: null,
                pollAttempts: 0,
                POLL_INTERVAL: 3000,
                POLL_MAX: 40,

                init() {
                    this.catalog.forEach(p => { this.byId[p.id] = p; });
                },

                // ---- the catalog ----
                matches(id) {
                    const p = this.byId[id];
                    if (! p) return false;
                    if (this.chip === 'for-you') {
                        // No season yet: everything rather than an empty grid.
                        if (this.forYou.length && ! this.forYou.includes(id)) return false;
                    } else if (this.chip !== '' && p.chip !== this.chip) {
                        return false;
                    }
                    if (this.brand !== '' && p.brand !== this.brand) return false;
                    const q = this.search.trim().toLowerCase();
                    return q === '' || p.brand.toLowerCase().includes(q) || p.name.toLowerCase().includes(q);
                },
                anyVisible() {
                    return this.catalog.some(p => this.matches(p.id));
                },

                // ---- "Приміряти": a toggle, up to the limit ----
                isPicked(id) {
                    return this.picked.includes(id);
                },
                togglePick(id) {
                    if (this.isPicked(id)) {
                        this.picked = this.picked.filter(x => x !== id);
                        return;
                    }
                    if (this.picked.length >= this.maxItems) {
                        this.say(this.t.max_items.replace(':count', this.maxItems));
                        return;
                    }
                    this.picked = [...this.picked, id];
                },
                itemsLabel(n) {
                    const form = new Intl.PluralRules(this.locale).select(n);
                    return (this.t.items[form] ?? this.t.items.other).replace(':count', n);
                },

                // ---- the heart: favourites, independent of the pick ----
                isLiked(id) {
                    return this.liked.includes(id);
                },
                // Optimistic: the heart flips at once and back if the server says no.
                async toggleLike(id) {
                    const liking = ! this.isLiked(id);
                    this.liked = liking ? [...this.liked, id] : this.liked.filter(x => x !== id);
                    let ok = false;
                    try {
                        const response = await fetch(this.routes.favorite.replace(/\/0$/, `/${id}`), {
                            method: liking ? 'POST' : 'DELETE',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                        });
                        ok = response.ok;
                    } catch (e) { /* flip back below */ }
                    if (! ok) this.liked = liking ? this.liked.filter(x => x !== id) : [...this.liked, id];
                },

                say(message, sticky = false) {
                    this.toast = message;
                    clearTimeout(this.toastTimer);
                    if (! sticky) this.toastTimer = setTimeout(() => { this.toast = ''; }, 3000);
                },

                // ---- the try-on ----
                get busy() {
                    return this.genState === 'processing';
                },
                canGenerate() {
                    return !! (this.memberLead && this.photoKey && this.picked.length && ! this.busy);
                },
                async generate() {
                    if (! this.canGenerate()) return;
                    this.result = null;
                    this.paywall = false;
                    this.toast = '';
                    this.genState = 'processing';
                    try {
                        // One piece is a try-on; two or more are a set.
                        const outfit = this.picked.length > 1;
                        const response = await fetch(outfit ? this.routes.outfit : this.routes.generate, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify({
                                lead_id: this.memberLead.id,
                                lead_token: this.memberLead.token,
                                ...(outfit ? { product_ids: this.picked } : { product_id: this.picked[0] }),
                                photo_key: this.photoKey,
                                consent_id: this.consentId,
                            }),
                        });
                        const data = await response.json().catch(() => ({}));
                        if (response.status === 402 || data.code === 'credits_exhausted') {
                            this.genState = 'idle';
                            this.paywall = true;
                            this.say(this.t.paywall, true);
                            return;
                        }
                        if (data.code === 'signup_required') {
                            window.location.href = this.routes.login;
                            return;
                        }
                        if (! response.ok) {
                            const errors = data.errors ? Object.values(data.errors).flat() : [];
                            this.fail(data.message ?? errors[0] ?? this.t.retry);
                            return;
                        }
                        this.statusUrl = data.status_url;
                        this.pollAttempts = 0;
                        this.poll();
                    } catch (error) {
                        this.fail(this.t.no_connection);
                    }
                },
                async poll() {
                    if (this.genState !== 'processing') return;
                    if (this.pollAttempts++ >= this.POLL_MAX) {
                        this.fail(this.t.too_long);
                        return;
                    }
                    try {
                        const response = await fetch(this.statusUrl, { headers: { 'Accept': 'application/json' } });
                        const data = await response.json();
                        if (data.status === 'done') {
                            const url = (data.result_urls && data.result_urls[0]) ? data.result_urls[0].url : null;
                            if (! url) return this.fail(this.t.retry);
                            this.genState = 'done';
                            this.result = url;
                            return;
                        }
                        if (data.status === 'failed') {
                            this.fail(data.message || this.t.retry);
                            return;
                        }
                    } catch (error) { /* try again on the next tick */ }
                    setTimeout(() => this.poll(), this.POLL_INTERVAL);
                },
                fail(message) {
                    this.genState = 'failed';
                    this.say(message);
                },
            };
        }
    </script>
@endpush

{{-- The phone's try-on (the design hand-over, the "Примірка" screen) in the site's
     monochrome: one viewport between the shell's top bar and tabs — photo,
     catalog, the button — on the dashboard's own state, so the upload, the
     try-on, the loader and the result screen are the dashboard's. It steps
     aside once a look is ready; "Приміряти іншу" brings it back. --}}
@php($heart = 'M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z')
<div class="mt only-mobile" id="m-tryon" x-show="! result"
     x-init="if (phone && myColours.length && ! category) mine = true">

    {{-- Hero + step 1 until the member adds a photo --}}
    <section class="mt-top" x-show="! photoUrl">
        <div class="mt-hero">
            <img class="mt-hero-img" id="m-tryon-hero" src="{{ asset('images/tryon/hero-model.png') }}" alt="{{ __('tryon_steps.hero_alt') }}">
            <div class="mt-intro">
                <h2 class="mt-title">AI Try-On</h2>
                <p class="mt-lede">{!! __('tryon_steps.lede_m') !!}</p>
                <ul class="mt-tips">
                    <li>
                        <span class="mt-tip-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"></path><circle cx="12" cy="13" r="3.5"></circle></svg></span>
                        <span>{{ __('tryon_steps.tips.full') }}</span>
                    </li>
                    <li>
                        <span class="mt-tip-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path></svg></span>
                        <span>{{ __('tryon_steps.tips.light') }}</span>
                    </li>
                    <li>
                        <span class="mt-tip-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="5" r="2.5"></circle><path d="M8 10h8M12 10v6M12 16l-3 6M12 16l3 6"></path></svg></span>
                        <span>{{ __('tryon_steps.tips.figure') }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <div class="mt-step1">
            <p class="mt-pill1"><span class="mt-num mt-num-1">1</span><span class="mt-pill-text">{{ __('tryon_steps.step1') }}</span></p>
            {{-- The file input is the dashboard's (x-ref="photo"). --}}
            <div class="mt-drop" id="m-tryon-drop" :class="{ over: dragOver }"
                 x-on:dragover.prevent="dragOver = true" x-on:dragleave.prevent="dragOver = false" x-on:drop.prevent="dropPhoto($event)">
                <div class="mt-drop-icon">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="14" rx="3"></rect><circle cx="9" cy="9" r="1.6"></circle><path d="M3 16l5-4 4 3 3-2 2 1.5"></path><circle cx="18.5" cy="18.5" r="4" fill="#F0F0F0"></circle><path d="M18.5 16.6v3.8M16.6 18.5h3.8"></path></svg>
                </div>
                <p class="mt-drop-title">
                    <img src="{{ asset('images/lk-mob/photo-added.svg') }}" width="14" height="14" alt="" x-show="photoUrl" x-cloak>
                    <span x-text="photoUrl ? @jsText(__('tryon_steps.photo_added')) : @jsText(__('tryon_steps.add_photo'))">{{ __('tryon_steps.add_photo') }}</span>
                </p>
                <p class="mt-drop-text" x-text="photoUrl ? @jsText(__('tryon_steps.pick_below')) : @jsText(__('tryon_steps.add_photo_text'))">{{ __('tryon_steps.add_photo_text') }}</p>
                <button type="button" class="mt-upload" id="m-tryon-upload" x-on:click="$refs.photo.value = ''; $refs.photo.click()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V5M7 10l5-5 5 5M4 20h16"></path></svg>
                    <span x-text="photoUrl ? @jsText(__('tryon_steps.change_photo')) : @jsText(__('tryon_steps.add_photo'))">{{ __('tryon_steps.add_photo') }}</span>
                </button>
                <p class="mt-formats">{{ __('tryon_steps.formats') }}</p>
            </div>
        </div>
    </section>

    {{-- With the photo in, step 1 folds into a strip so the pieces get the height. --}}
    <section class="mt-strip" id="m-tryon-strip" x-show="photoUrl" x-cloak>
        <img class="mt-strip-photo" id="m-tryon-photo" :src="photoUrl" alt="{{ __('tryon_steps.photo_alt') }}">
        <div class="mt-strip-text">
            <p class="mt-strip-step"><span class="mt-num mt-num-1">1</span><span>{{ __('tryon_steps.step1_short') }}</span></p>
            <p class="mt-strip-title"><img src="{{ asset('images/lk-mob/photo-added.svg') }}" width="14" height="14" alt="">{{ __('tryon_steps.photo_added') }}</p>
        </div>
        <button type="button" class="mt-change" id="m-tryon-change" x-on:click="$refs.photo.value = ''; $refs.photo.click()">{{ __('tryon_steps.change') }}</button>
    </section>

    {{-- Step 2: under the model's feet while the hero is up (raised from the
         first paint, before Alpine), under the strip once the photo is in. --}}
    <div class="mt-step2 is-raised" :class="{ 'is-raised': ! photoUrl }">
        <p class="mt-pill2"><span class="mt-num mt-num-2">2</span><span class="mt-pill-text">{{ __('tryon_steps.step2_pill') }}</span></p>
    </div>

    {{-- Search + one brand --}}
    <div class="mt-search-row" x-on:click.outside="brandOpen = false" x-on:keydown.escape="brandOpen = false">
        <label class="mt-search">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="M20 20l-4-4"></path></svg>
            <input type="search" id="m-tryon-search" x-model.debounce.150ms="query" placeholder="{{ __('tryon_steps.search_label') }}" aria-label="{{ __('tryon_steps.search_label') }}">
        </label>
        <button type="button" class="mt-brands" id="m-tryon-brands" :aria-expanded="brandOpen" aria-haspopup="true" x-on:click="brandOpen = ! brandOpen">
            <span x-text="brands.length ? brands.join(', ') : @jsText(__('tryon_steps.all_brands'))">{{ __('tryon_steps.all_brands') }}</span>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"></path></svg>
        </button>
        <ul class="mt-brand-list" x-show="brandOpen" x-cloak x-transition.opacity>
            <li><button type="button" :aria-pressed="brands.length === 0" x-on:click="brands = []; brandOpen = false">{{ __('tryon_steps.all_brands') }}</button></li>
            <template x-for="b in Object.keys(brandCounts)" :key="b">
                <li><button type="button" :aria-pressed="brands.length === 1 && brands[0] === b" x-on:click="brands = [b]; brandOpen = false" x-text="b"></button></li>
            </template>
        </ul>
    </div>

    {{-- Chips: "Для тебе" (the member's season, when there is one), "Усі", the feed's categories --}}
    <div class="mt-chips" id="m-tryon-chips" role="group"
         x-on:wheel="if (Math.abs($event.deltaY) > Math.abs($event.deltaX) && $el.scrollWidth > $el.clientWidth) { $el.scrollLeft += $event.deltaY; $event.preventDefault() }">
        @if ($myColourIds)
            <button type="button" class="mt-chip" :aria-pressed="mine" x-on:click="mine = true; category = ''">{{ __('tryon_steps.for_you') }}</button>
        @endif
        @if ($categories)
            <button type="button" class="mt-chip" :aria-pressed="! mine && category === ''" x-on:click="mine = false; category = ''">{{ __('tryon_steps.all') }}</button>
        @endif
        @foreach ($categories as $chip)
            <button type="button" class="mt-chip" :aria-pressed="! mine && category === @js($chip)" x-on:click="mine = false; category = @js($chip)">{{ $chip }}</button>
        @endforeach
    </div>

    {{-- Cards: the only flexible block --}}
    <div class="mt-grid" id="m-tryon-grid">
        @foreach ($products as $product)
            <article class="mt-card" :class="{ 'is-picked': selected === {{ $product->id }} }"
                     x-show="matches(@js($product->brand_name), @js($product->product_name), @js((string) $product->category_label), {{ $product->id }})">
                <div class="mt-card-pic">
                    <button type="button" class="mt-card-open" aria-label="{{ __('dashboard.details_of', ['name' => $product->product_name]) }}" x-on:click="openDetail({{ $product->id }})">
                        <img src="{{ $product->image_flat_url }}" alt="{{ $product->brand_name }} {{ $product->product_name }}" loading="lazy">
                    </button>
                    <button type="button" class="mt-like" aria-label="{{ __('tryon_steps.like') }}" :aria-pressed="isLiked({{ $product->id }})"
                            x-on:click="toggleLike({{ $product->id }})">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $heart }}"></path></svg>
                    </button>
                </div>
                <div class="mt-brand">{{ $product->brand_name }}</div>
                <div class="mt-name">{{ $product->product_name }}</div>
                <div class="mt-buy">
                    <div class="mt-price">AED {{ rtrim(rtrim(number_format((float) $product->price_aed, 2, '.', ''), '0'), '.') }}</div>
                    <button type="button" class="mt-try" :aria-pressed="selected === {{ $product->id }}" x-on:click="toggle({{ $product->id }})"
                            x-text="selected === {{ $product->id }} ? @jsText(__('tryon_steps.picked')) : @jsText(__('tryon_steps.try'))">{{ __('tryon_steps.try') }}</button>
                </div>
            </article>
        @endforeach
    </div>
    <p class="mt-empty" id="m-tryon-empty" x-show="! Object.values(details).some(p => matches(p.brand ?? '', p.name ?? '', p.category ?? '', p.id))" x-cloak>{{ __('tryon_steps.empty') }}</p>

    {{-- Step 3: what went wrong, if anything, and the button with its number inside --}}
    <p class="mt-status" id="m-tryon-status" aria-live="polite" x-show="genState === 'failed'" x-cloak x-text="genMessage"></p>
    @if (config('consent.required'))
        <div class="mt-consent" id="m-tryon-consent" role="group">
            <label><input type="checkbox" x-model="consent"><span>{{ config('consent.text') }}</span></label>
            <label><input type="checkbox" x-model="terms"><span>{{ config('terms.text') }}
                <a href="{{ config('legal.base_url') }}/terms-of-use" target="_blank" rel="noopener">Terms</a>.</span></label>
        </div>
    @endif
    <div class="mt-go-wrap">
        <button type="button" class="mt-go" id="m-tryon-generate" :disabled="! canGenerate()" x-on:click="generate()">
            <span class="mt-num mt-num-3" aria-hidden="true">3</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10 3l1.8 5.2L17 10l-5.2 1.8L10 17l-1.8-5.2L3 10l5.2-1.8zM18 14l.9 2.6L21.5 17.5l-2.6.9L18 21l-.9-2.6L14.5 17.5l2.6-.9z"></path></svg>
            <span x-text="busy ? @jsText(__('tryon_steps.busy')) : @jsText(__('tryon_steps.generate'))">{{ __('tryon_steps.generate') }}</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"></path></svg>
        </button>
    </div>
</div>

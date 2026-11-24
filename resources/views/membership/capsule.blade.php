@extends('membership.shell')

@section('title', __('capsules.ui.title_one', ['name' => __("capsules.occasions.{$occasion}")]))

{{-- One capsule, Figma 1958:10473 (desktop) / 1962:8107 (phone): its name,
     colours and cover, then every piece its looks use (try on, buy, heart),
     then the looks themselves as the ways to wear them. Brand, price and the
     shop link come from the product row (via the click-counting redirect);
     "Приміряти" opens the try-on with the piece picked, as on the colour page,
     and only for what the try-on can dress. --}}

@section('styles')
        .capsule-top { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 30px 40px; }
        .capsule-intro { flex: 1 1 380px; min-width: 0; }
        .back { display: inline-flex; align-items: center; gap: 4px; font-size: 16px; line-height: 1.5; color: var(--muted);
            text-decoration: none; transition: color .3s ease; }
        .back:hover { color: var(--ink-2); }
        .capsule-intro h1 { margin-top: 20px; font-size: 38px; line-height: 41.6px; color: var(--ink-2); }
        .capsule-count { display: inline-block; margin-top: 12px; padding: 2px 12px; border-radius: 15px; background: var(--surface-2);
            font-size: 16px; line-height: 1.5; color: var(--muted); }
        .capsule-intro .lede { margin-top: 12px; }
        .capsule-swatches { display: flex; gap: 12px; margin-top: 20px; }
        .capsule-swatches i { width: 54px; height: 54px; border-radius: 50%; box-shadow: inset 0 0 0 1px rgba(45, 45, 45, .08); }
        .capsule-hero { position: relative; flex: 0 1 732px; aspect-ratio: 732 / 260; overflow: hidden; }
        .capsule-cover { position: absolute; inset: 0; background: var(--surface-2); }
        .capsule-cover .cover-photo { width: 100%; height: 100%; object-fit: cover; }
        .cover-strip { display: flex; justify-content: center; align-items: center; gap: 16px; height: 100%; padding: 24px; }
        .cover-strip img { flex: 0 1 auto; min-width: 0; height: 100%; aspect-ratio: 3 / 4; object-fit: contain; background: var(--surface); }

        .capsule-block { margin-top: 60px; }
        .capsule-block > h2 { font-size: 22px; line-height: 41.6px; color: var(--ink-2); }
        .capsule-block > .sub { margin: 8px 0 0; font-size: 16px; line-height: 1.5; color: var(--muted); }
        .capsule-note { margin: 30px 0 0; padding: 24px; background: var(--surface-2); font-size: 14px; line-height: 1.5; color: var(--muted); }

        /* The pieces: 297px cards, five a row at 1920 (Figma 1958:10473) and
           never more; narrower, as many 240px-or-wider columns as fit. */
        .piece-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(max(240px, calc((100% - 4 * 24px) / 5)), 1fr));
            gap: 30px 24px; margin-top: 16px; }
        .piece-card { position: relative; display: flex; flex-direction: column; gap: 14px; min-width: 0; }
        .piece-card .pic { aspect-ratio: 297 / 323; overflow: hidden; background: var(--surface); }
        .piece-card .pic img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .piece-card .text { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
        .piece-card .text > * { margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .piece-card .brand { font-size: 14px; line-height: 1.5; text-transform: uppercase; color: var(--muted); }
        .piece-card .name { font-family: var(--serif); font-size: 16px; line-height: 1.3; color: var(--ink-2); }
        .piece-card .price { font-size: 16px; line-height: 1.5; color: var(--muted); }
        .piece-card .actions { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; margin-top: auto; }
        .piece-card .actions .dl-btn { min-height: 50px; padding: 10px 8px; letter-spacing: .13em; }
        .heart { position: absolute; top: 12px; inset-inline-end: 12px; width: 32px; height: 32px; display: flex; align-items: center;
            justify-content: center; padding: 0; border: none; border-radius: 50%; background: var(--surface); color: var(--ink-2); cursor: pointer; }
        .heart.active .heart-fill { fill: currentColor; }
        .heart:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }

        /* Ways to wear it: 378 x 165, the look's lead piece big, the rest small */
        .way-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 360px), 1fr)); gap: 24px; margin-top: 24px; }
        .way-card { display: grid; grid-template-columns: 152px minmax(0, 1fr); gap: 10px; height: 165px; }
        .way-card .lead { height: 100%; background: var(--surface); overflow: hidden; }
        .way-card .lead img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .way-card .body { display: flex; flex-direction: column; justify-content: space-between; gap: 16px; min-width: 0; }
        .way-card h3 { font-size: 16px; line-height: normal; color: var(--ink-2); }
        .way-card .slots { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; margin-top: 8px;
            font-size: 16px; line-height: 1.5; color: var(--muted); }
        .way-card .slots svg { flex: none; }
        .way-card .minis { display: flex; gap: 8px; }
        .way-card .minis span { width: 66px; height: 71px; overflow: hidden; background: var(--surface-2); }
        .way-card .minis img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .ways-meter { display: none; }

        /* ---- Phone, Figma 1962:8107 (375px) ---- */
        @media (max-width: 833px) {
            .capsule-top { gap: 12px; }
            .capsule-intro { flex-basis: 100%; }
            .back { font-size: 9px; gap: 2px; }
            .back svg { width: 14px; height: 14px; }
            .capsule-intro .title-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 12px; }
            .capsule-intro h1 { margin: 0; font-size: 16px; line-height: 25px; }
            .capsule-count { margin: 0; padding: 2px 8px; font-size: 9px; }
            .capsule-intro .lede { margin: 4px 0 0; font-size: 12px; }
            .capsule-swatches { display: none; }
            .capsule-hero { flex-basis: 100%; aspect-ratio: 335 / 129; }
            .cover-strip { gap: 8px; padding: 10px; }

            .capsule-block { margin-top: 20px; }
            .capsule-block > h2 { font-size: 16px; line-height: 23px; }
            .capsule-block > .sub { margin-top: 4px; font-size: 12px; }
            .piece-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px 13px; margin-top: 12px; }
            .piece-card { gap: 6px; }
            .piece-card .pic { aspect-ratio: 161 / 176; border-radius: 6px; }
            .piece-card .text { gap: 4px; }
            .piece-card .brand { font-size: 9px; }
            .piece-card .name { font-size: 12px; }
            .piece-card .price { font-size: 12px; }
            .piece-card .actions { gap: 4px; }
            .piece-card .actions .dl-btn { min-height: 22px; padding: 4px 2px; border-radius: 4px; font-size: 9px; letter-spacing: .13em; line-height: 1; }
            .piece-card .actions .dl-btn-outline { border-color: rgba(45, 45, 45, .6); }
            .heart { top: 6px; inset-inline-end: 6px; width: 24px; height: 24px; }
            .heart svg { width: 16px; height: 16px; }

            /* Ways: a sideways row of 161 x 176 cards, the copy over the
               picture, a thin line that follows the scroll */
            .way-cards { display: flex; gap: 13px; margin-top: 12px; overflow-x: auto; scroll-snap-type: x mandatory; scrollbar-width: none; }
            .way-cards::-webkit-scrollbar { display: none; }
            .way-card { position: relative; flex: none; width: 161px; height: 176px; min-height: 0; display: block;
                border-radius: 6px; overflow: hidden; scroll-snap-align: start; }
            .way-card .lead { position: absolute; inset: 0; }
            .way-card .lead::after { content: ''; position: absolute; inset: 0;
                background: linear-gradient(180deg, rgba(45, 45, 45, 0) 54%, rgba(45, 45, 45, .6) 73%, rgba(45, 45, 45, .8)); }
            .way-card .body { position: absolute; inset-inline-start: 6px; inset-inline-end: 6px; bottom: 8px; gap: 0; }
            .way-card h3 { font-size: 12px; color: var(--surface); }
            .way-card .slots { flex-wrap: nowrap; gap: 4px; margin-top: 8px; font-size: 9px; color: var(--surface); overflow: hidden; white-space: nowrap; }
            .way-card .slots svg { width: 12px; height: 12px; }
            .way-card .minis { display: none; }
            .ways-meter { display: block; height: 2px; margin-top: 8px; background: rgba(45, 45, 45, .04); }
            .ways-meter span { display: block; height: 100%; background: var(--ink-2); }
        }
@endsection

@section('section')
    @php($pieces = $set->pieces())
    @php($name = __("capsules.occasions.{$occasion}"))
    @php($countLabel = trans_choice('capsules.pieces', count($pieces)))
    <div class="section-rise dl" id="capsule-section" data-occasion="{{ $occasion }}" x-data="capsulePage()">
        <div class="capsule-top">
            <div class="capsule-intro">
                <a class="back" href="{{ route('membership.account.capsules', ['plan' => $plan]) }}">
                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><path d="M11 4L6 9l5 5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    {{ __('capsules.ui.back') }}
                </a>
                <div class="title-row">
                    <h1>{{ $name }}</h1>
                    @unless ($set->isEmpty())
                        <span class="capsule-count only-mobile">{{ $countLabel }}</span>
                    @endunless
                </div>
                @unless ($set->isEmpty())
                    <span class="capsule-count only-desktop">{{ $countLabel }}</span>
                @endunless
                <p class="lede">{{ __("capsules.blurbs.{$occasion}") }}</p>
                @if ($colours = $set->colours())
                    <div class="capsule-swatches" aria-label="{{ __('capsules.ui.colours') }}">
                        @foreach ($colours as $hex)<i style="background: {{ $hex }};" title="{{ $hex }}"></i>@endforeach
                    </div>
                @endif
            </div>
            @unless ($set->isEmpty())
                <div class="capsule-hero">
                    @include('membership.partials.capsule-cover', ['occasion' => $occasion, 'set' => $set])
                </div>
            @endunless
        </div>

        @if ($set->isEmpty())
            <p class="capsule-note capsule-empty" id="capsule-empty">
                @if ($set->reason === \App\Services\Capsules\CapsuleSet::NO_PIECES)
                    {{ __('capsules.missing', [
                        'occasion' => __("capsules.occasion_for.{$occasion}"),
                        'slots' => collect($set->missingSlots)->map(fn ($slot) => __("capsules.slots.{$slot}"))->implode(__('capsules.slots_glue')),
                    ]) }}
                @elseif ($set->reason === \App\Services\Capsules\CapsuleSet::NO_OCCASION_PIECES)
                    {{ __('capsules.no_occasion_pieces', ['occasion' => __("capsules.occasion_for.{$occasion}")]) }}
                @else
                    {{ __('capsules.no_balanced_look', ['occasion' => __("capsules.occasion_for.{$occasion}")]) }}
                @endif
            </p>
        @else
            <section class="capsule-block" id="capsule-pieces">
                <h2>{{ __('capsules.ui.pieces_heading', ['count' => $countLabel]) }}</h2>
                <div class="piece-cards">
                    @foreach ($pieces as $product)
                        <article class="piece-card" data-slot="{{ $product->category }}">
                            <div class="pic"><img src="{{ $product->image_flat_url }}" alt="{{ $product->product_name }}" loading="lazy"></div>
                            <button type="button" class="heart" :class="{ active: favorites.includes({{ $product->id }}) }"
                                    :aria-pressed="favorites.includes({{ $product->id }})" aria-label="{{ __('capsules.ui.favorite') }}"
                                    x-on:click="toggleFavorite({{ $product->id }})">
                                <svg width="20" height="20" viewBox="0 0 28 28" fill="none" aria-hidden="true"><path class="heart-fill" d="M14 23.5S3.5 17.4 3.5 10.3A5.3 5.3 0 0114 7.6a5.3 5.3 0 0110.5 2.7c0 7.1-10.5 13.2-10.5 13.2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" fill="none"/></svg>
                            </button>
                            <div class="text">
                                <p class="brand">{{ $product->brand_name }}</p>
                                <h3 class="name" title="{{ $product->product_name }}">{{ $product->product_name }}</h3>
                                <p class="price">{{ number_format((float) $product->price_aed, 2, '.', ' ') }} AED</p>
                            </div>
                            <div class="actions">
                                @if (in_array($product->category, \App\Models\Product::TRY_ON_CATEGORIES, true))
                                    <a class="dl-btn dl-btn-outline" href="{{ route('membership.account.dashboard', ['pick' => $product->id, 'plan' => $plan]) }}#catalog-grid">{{ __('capsules.ui.try_on') }}</a>
                                @else
                                    <button type="button" class="dl-btn dl-btn-outline" disabled title="{{ __('capsules.ui.try_on_unavailable') }}">{{ __('capsules.ui.try_on') }}</button>
                                @endif
                                <a class="dl-btn dl-btn-dark" href="{{ route('membership.account.shop', ['product' => $product->id, 'from' => 'capsule']) }}" target="_blank" rel="noopener">{{ __('capsules.ui.buy') }}</a>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="capsule-block" id="capsule-ways">
                <h2>{{ __('capsules.ui.ways') }}</h2>
                <p class="sub">{{ __('capsules.ui.ways_sub') }}</p>
                <div class="way-cards" x-ref="ways" x-on:scroll.passive="meter()">
                    @foreach ($set->capsules as $look)
                        @php($lookPieces = array_values($look->pieces))
                        <article class="way-card" data-kind="{{ $look->kind }}" title="{{ $look->explanation }} {{ __('capsules.ui.match', ['percent' => $look->percent()]) }}">
                            <div class="lead"><img src="{{ $lookPieces[0]->image_flat_url }}" alt="" loading="lazy"></div>
                            <div class="body">
                                <div>
                                    <h3>{{ __('capsules.ui.look', ['n' => $loop->iteration]) }}</h3>
                                    <div class="slots">
                                        @foreach (array_keys($look->pieces) as $slot)
                                            @unless ($loop->first)
                                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 3.5v9M3.5 8h9" stroke="currentColor" stroke-width="1" stroke-linecap="round"/></svg>
                                            @endunless
                                            <span>{{ __("capsules.slots.{$slot}") }}</span>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="minis">
                                    @foreach (array_slice($lookPieces, 1, 3) as $piece)
                                        <span><img src="{{ $piece->image_flat_url }}" alt="{{ $piece->product_name }}" loading="lazy"></span>
                                    @endforeach
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
                <div class="ways-meter" aria-hidden="true"><span :style="`width: ${bar.width}%; margin-inline-start: ${bar.left}%`"></span></div>
            </section>
        @endif
    </div>
@endsection

@section('scripts')
    <script>
        function capsulePage() {
            return {
                // Hearted pieces, from the account — the same list as the
                // dashboard's and the wardrobe's hearts.
                favorites: @json($favorites),
                favoriteRoute: @json($favoriteRoute),
                // The phone's line under the sideways looks: how much of the
                // row is in view, and where.
                bar: { width: 100, left: 0 },
                init() {
                    this.$nextTick(() => this.meter());
                },
                meter() {
                    const row = this.$refs.ways;
                    if (! row || row.scrollWidth <= row.clientWidth) {
                        this.bar = { width: 100, left: 0 };
                        return;
                    }
                    const width = row.clientWidth / row.scrollWidth * 100;
                    this.bar = { width, left: Math.abs(row.scrollLeft) / row.scrollWidth * 100 };
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
            };
        }
    </script>
@endsection

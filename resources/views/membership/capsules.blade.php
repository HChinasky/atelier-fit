@extends('membership.shell')

@section('title', __('capsules.ui.title'))

{{-- Capsules, Figma 1907:9446 (desktop) / 1958:10180 (phone): one capsule per
     occasion. A capsule is the looks CapsuleBuilder puts together from the
     catalog in the member's palette; its card counts the pieces those looks
     use and shows their colours, and "Переглянути капсулу" opens it
     (membership.capsule). --}}

@section('styles')
        .capsule-note { margin: 30px 0 0; padding: 24px; background: var(--surface-2); font-size: 14px; line-height: 1.5; color: var(--muted); }
        .capsule-note a { color: var(--ink-2); }

        /* Cards: 780 x 344 at 1920, two a row (24px apart, 50px between rows) */
        .capsule-cards { display: grid; grid-template-columns: minmax(0, 1fr); gap: 50px 24px; margin-top: 24px; }
        @media (min-width: 1200px) { .capsule-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .capsule-card { display: flex; flex-direction: column; gap: 12px; min-width: 0; }
        .capsule-card .cover-wrap { position: relative; display: block; aspect-ratio: 780 / 278; overflow: hidden; color: var(--surface); text-decoration: none; }
        .capsule-cover { position: absolute; inset: 0; background: var(--surface-2); }
        /* Sharp, not blurred: the frame's 4px blur read as a broken image on a desktop.
           The ink gradient from the left keeps the white copy readable. */
        .capsule-cover .cover-photo { width: 100%; height: 100%; object-fit: cover; }
        /* The first look as white tiles on the grey: white pieces stay visible */
        .cover-strip { position: absolute; top: 0; inset-inline-end: 0; bottom: 0; width: 56%; display: flex; justify-content: flex-end;
            align-items: center; gap: 12px; padding: 24px; }
        .cover-strip img { flex: 0 1 auto; min-width: 0; height: 100%; aspect-ratio: 3 / 4; object-fit: contain; background: var(--surface); }
        /* Ink from the left, so the white copy reads over any picture */
        .capsule-card .cover-wrap::after { content: ''; position: absolute; inset: 0;
            background: linear-gradient(to left, rgba(45, 45, 45, 0) 43%, rgba(45, 45, 45, .7)); }
        .capsule-card .cover-text { position: absolute; z-index: 1; inset-inline-start: 30px; bottom: 30px; max-width: min(356px, 38%);
            display: flex; flex-direction: column; gap: 12px; }
        .capsule-card .count { font-size: 9.5px; letter-spacing: .3em; text-transform: uppercase; }
        .capsule-card h2 { font-size: 22px; line-height: 32px; color: var(--surface); }
        .capsule-card .blurb { margin: 0; font-size: 14px; line-height: 1.5; }
        .capsule-card .foot { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
        .capsule-swatches { display: flex; gap: 12px; }
        .capsule-swatches i { width: 54px; height: 54px; border-radius: 50%; box-shadow: inset 0 0 0 1px rgba(45, 45, 45, .08); }
        .capsule-card .dl-btn { flex: none; padding: 15px 23px; }

        /* ---- Phone, Figma 1958:10180 (375px): centred head, 335 x 110
           covers with the copy under them, 18px swatches beside the name. ---- */
        @media (max-width: 833px) {
            .dl-head > div { width: 100%; text-align: center; }
            .dl .lede { margin-inline-start: auto; margin-inline-end: auto; }
            .dl-head .eyebrow { display: none; }
            .dl-head h1 { font-size: 16px; line-height: 21px; }
            .dl-head .lede { margin-top: 4px; font-size: 12px; line-height: 1.5; }

            .capsule-cards { gap: 24px; margin-top: 12px; }
            .capsule-card { gap: 10px; }
            .capsule-card .cover-wrap { aspect-ratio: 335 / 110; }
            .capsule-card .cover-wrap::after, .capsule-card .cover-text { display: none; }
            .cover-strip { width: 100%; justify-content: center; gap: 8px; padding: 10px; }
            .capsule-card .copy.only-mobile { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
            .capsule-card .copy .row { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
            .capsule-card .copy h2 { font-size: 14px; line-height: normal; color: var(--ink-2);
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .capsule-card .copy .capsule-swatches { gap: 8px; }
            .capsule-card .copy .capsule-swatches i { width: 18px; height: 18px; }
            .capsule-card .copy .count { font-size: 9px; letter-spacing: 0; color: var(--muted); }
            .capsule-card .copy .blurb { font-size: 9px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .capsule-card .foot { display: block; }
            .capsule-card .foot .capsule-swatches { display: none; }
            .capsule-card .dl-btn { width: 100%; min-height: 32px; padding: 6px; border-radius: 4px; font-size: 9px; letter-spacing: .13em; }
        }
@endsection

@section('section')
    <div class="section-rise dl" id="capsules-section">
        <div class="dl-head">
            <div>
                <div class="eyebrow">{{ __('capsules.ui.eyebrow') }}</div>
                <h1>{{ __('capsules.ui.heading') }}</h1>
                <p class="lede">{{ __('capsules.ui.lede') }}</p>
            </div>
        </div>

        @if ($locked)
            <div class="locked-box" id="capsules-locked" style="margin-top: 30px;">
                <div class="locked-preview caps"><div></div><div></div></div>
                <div class="locked-overlay">
                    <div class="lock-badge"><svg width="19" height="19" viewBox="0 0 16 16" fill="none"><rect x="3.4" y="7.2" width="9.2" height="6.2" rx="1.5" stroke="#6B6B6B" stroke-width="1.2"/><path d="M5.5 7.2V5.4a2.5 2.5 0 015 0V7.2" stroke="#6B6B6B" stroke-width="1.2"/></svg></div>
                    <h3>{{ __('capsules.ui.locked_title') }}</h3>
                    <p>{{ __('capsules.ui.locked_text') }}</p>
                    <a class="btn-gold-outline" style="text-decoration:none;" href="{{ route('membership.pricing') }}">{{ __('capsules.ui.locked_cta') }}</a>
                </div>
            </div>
        @elseif ($state === 'colour_off')
            <p class="capsule-note" id="capsules-colour-off">{{ __('capsules.ui.colour_off') }}</p>
        @elseif ($state === 'no_season')
            <p class="capsule-note" id="capsules-no-season">
                {!! __('capsules.ui.no_season', ['link' => '<a href="'.e(route('membership.account.color')).'">'.e(__('capsules.ui.no_season_link')).'</a>']) !!}
            </p>
        @else
            <div class="capsule-cards" id="capsule-cards">
                @foreach ($sets as $occasion => $set)
                    @php($pieces = $set->pieces())
                    @php($colours = $set->colours())
                    @php($href = route('membership.account.capsule', ['occasion' => $occasion, 'plan' => $plan]))
                    @php($count = $set->isEmpty() ? __('capsules.ui.gathering') : trans_choice('capsules.pieces', count($pieces)))
                    <article class="capsule-card" id="capsule-{{ $occasion }}" data-occasion="{{ $occasion }}">
                        <a class="cover-wrap" href="{{ $href }}" tabindex="-1" aria-hidden="true">
                            @include('membership.partials.capsule-cover', ['occasion' => $occasion, 'set' => $set])
                            <div class="cover-text">
                                <span class="count">{{ $count }}</span>
                                <h2>{{ __("capsules.occasions.{$occasion}") }}</h2>
                                <p class="blurb">{{ __("capsules.blurbs.{$occasion}") }}</p>
                            </div>
                        </a>
                        <div class="copy only-mobile">
                            <div class="row">
                                <h2>{{ __("capsules.occasions.{$occasion}") }}</h2>
                                <div class="capsule-swatches">
                                    @foreach ($colours as $hex)<i style="background: {{ $hex }};"></i>@endforeach
                                </div>
                            </div>
                            <span class="count">{{ $count }}</span>
                            <p class="blurb">{{ __("capsules.blurbs.{$occasion}") }}</p>
                        </div>
                        <div class="foot">
                            <div class="capsule-swatches" aria-label="{{ __('capsules.ui.colours') }}">
                                @foreach ($colours as $hex)<i style="background: {{ $hex }};" title="{{ $hex }}"></i>@endforeach
                            </div>
                            <a class="dl-btn dl-btn-dark" href="{{ $href }}">{{ __('capsules.ui.open') }}</a>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
@endsection

@extends('membership.shell')

@section('title', __('pages.vip.title'))

@section('styles')
        /* ---- Diva VIP: stylist area ---- */
        .vip-eyebrow-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
        .vip-eyebrow-row .flair { font-family: 'Playfair Display', serif; font-size: 16px; color: var(--gold); }
        .vip-eyebrow-row .eyebrow { margin-bottom: 0; }

        .vip-review-card { background: radial-gradient(circle at 30% 0%, #2D2D2D, #2D2D2D);
            border: 1px solid rgba(107, 107, 107, .28); border-radius: 0; padding: 24px;
            margin: 20px 0 16px; display: flex; align-items: center; justify-content: space-between;
            gap: 18px; flex-wrap: wrap; }
        .vip-review-card .caption { font-size: 9.5px; letter-spacing: .24em; color: rgba(255, 255, 255, .55);
            text-transform: uppercase; margin-bottom: 7px; }
        .vip-review-card .status { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--surface); }
        .btn-vip-gold { padding: 15px 26px; border: 1px solid var(--surface); border-radius: 0;
            background: var(--surface); font-size: 11px; letter-spacing: .16em;
            color: var(--ink); text-transform: uppercase; white-space: nowrap; cursor: pointer;
            transition: transform .3s ease, box-shadow .3s ease; }
        .btn-vip-gold:hover { transform: translateY(-2px); box-shadow: 0 16px 30px -14px rgba(0, 0, 0, .5); }

        .verdict-card { border: 1px solid rgba(45, 45, 45, .1); border-radius: 0;
            background: #FFFFFF; padding: 24px; }
        .verdict-head { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
        .verdict-head .avatar { width: 44px; height: 44px; border-radius: 50%; flex: none;
            background: repeating-linear-gradient(45deg, #F0F0F0 0 5px, rgba(45, 45, 45, .05) 5px 10px); }
        .verdict-head .who { flex: 1; }
        .verdict-head .name { font-family: 'Playfair Display', serif; font-size: 16px; color: var(--ink-2); }
        .verdict-head .meta { font-size: 11px; letter-spacing: .06em; color: #6B6B6B; }
        .verdict-head .flair { font-family: 'Playfair Display', serif; font-size: 18px; color: var(--gold); }

        /* Audio-player stub: play button + animated EQ bars (no real audio yet). */
        .audio-stub { display: flex; align-items: center; gap: 14px; padding: 14px 16px;
            background: #2D2D2D; border-radius: 0; margin-bottom: 16px; }
        .audio-stub .play { width: 40px; height: 40px; border-radius: 50%; background: var(--surface);
            border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; flex: none; }
        .audio-stub .eq { flex: 1; display: flex; align-items: center; gap: 2px; height: 26px; }
        .audio-stub .eq span { flex: 1; background: rgba(255, 255, 255, .5); border-radius: 0; height: 100%;
            transform-origin: center; }
        .audio-stub .time { font-size: 11px; color: rgba(255, 255, 255, .6); flex: none; }
        .verdict-text { font-weight: 300; font-size: 13.5px; line-height: 1.72; color: #6B6B6B; margin: 0; }
@endsection

@section('section')
    <div class="section-rise" id="vip-section">
        <div class="vip-eyebrow-row">
            <span class="flair">⚜</span>
            <div class="eyebrow">Diva VIP</div>
        </div>
        <h1 class="page-title">{{ __('pages.vip.heading') }}</h1>

        <div class="vip-review-card">
            <div>
                <div class="caption">{{ __('pages.vip.review') }}</div>
                <div class="status">{{ __('pages.vip.review_status') }}</div>
            </div>
            <button class="btn-vip-gold" type="button">{{ __('pages.vip.send') }}</button>
        </div>

        <div class="verdict-card">
            <div class="verdict-head">
                <span class="avatar"></span>
                <div class="who">
                    <div class="name">{{ $stylist['name'] }}</div>
                    <div class="meta">{{ $stylist['meta'] }}</div>
                </div>
                <span class="flair">⚜</span>
            </div>
            <div class="audio-stub" id="verdict-audio">
                <button class="play" type="button" aria-label="{{ __('pages.vip.play') }}">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="#2D2D2D"><path d="M3 2l9 5-9 5z"/></svg>
                </button>
                <div class="eq">
                    @foreach (range(0, 21) as $i)
                        <span style="opacity:{{ number_format(.4 + ($i % 5) * .12, 2) }};animation:neq {{ number_format(.8 + ($i % 5) * .18, 2) }}s ease-in-out {{ number_format($i * .05, 2) }}s infinite;"></span>
                    @endforeach
                </div>
                <span class="time">{{ $stylist['duration'] }}</span>
            </div>
            <p class="verdict-text">{{ $stylist['verdict'] }}</p>
        </div>
    </div>
@endsection

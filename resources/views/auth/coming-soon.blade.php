@extends('membership.layout')

@section('title', __('coming_soon.title'))

{{-- The page after the sign-in field while the atelier is not open yet
     (FEATURE_COMING_SOON): the address is kept, nothing else has happened, and
     the person is told both. Drawn as the sign-in card so the two read as one
     flow; the only new element is the kept address, shown back masked. --}}

@section('styles')
        .soon-page { min-height: 100vh; display: flex; align-items: flex-start; justify-content: center;
            padding: 44px 26px 56px; background: radial-gradient(120% 80% at 50% 0%, #FFFFFF, #F0F0F0); }
        @media (min-width: 768px) { .soon-page { align-items: center; padding: 40px; } }
        .soon-card { width: 100%; max-width: 420px; animation: nrise .55s var(--ease) both; }
        @media (min-width: 768px) {
            .soon-card { width: 440px; max-width: 100%; background: var(--surface);
                border: 1px solid rgba(45, 45, 45, .08); border-radius: 0; padding: 40px;
                box-shadow: 0 30px 70px -34px rgba(45, 45, 45, .4); }
        }
        .soon-brand { display: block; height: 34px; width: auto; margin: 0 auto 30px; }
        .soon-card .lede { margin: 0; max-width: 34ch; }
        /* The kept address: one quiet line on the second surface, most of the
           mailbox hidden. */
        .soon-number { display: flex; align-items: center; gap: 12px; margin: 24px 0 0; padding: 14px 16px;
            background: var(--surface-2); border-radius: 0; }
        .soon-number svg { flex: none; }
        .soon-number .num { font-size: 15px; letter-spacing: .03em; color: var(--ink-2); overflow-wrap: anywhere; }
        .soon-number .note { display: block; margin-top: 2px; font-size: 11px; letter-spacing: .03em; color: var(--muted-2); }
        /* The way out: the same dark button as the sign-in step, drawn as a link */
        .soon-home { display: flex; align-items: center; justify-content: center; margin-top: 24px; text-decoration: none; }
        .soon-alt { margin-top: 26px; padding-top: 20px; border-top: 1px solid rgba(45, 45, 45, .08);
            display: flex; flex-direction: column; align-items: center; gap: 12px; text-align: center; }
        .soon-alt a { font-size: 11px; letter-spacing: .16em; text-transform: uppercase;
            text-decoration: none; color: #6B6B6B; transition: color .3s ease; }
        .soon-alt a:hover { color: var(--ink-2); }
        .soon-legal { margin: 18px auto 0; max-width: 34ch; text-align: center; font-weight: 300;
            font-size: 10.5px; line-height: 1.6; letter-spacing: .02em; color: var(--muted-2); }
        .soon-legal a { color: var(--muted-2); text-decoration: underline; }
@endsection

@section('content')
    <div class="soon-page">
        <div class="soon-card" id="coming-soon">
            <img class="soon-brand" src="{{ asset('Logo_dark.svg') }}" alt="{{ __('common.logo_alt') }}">

            <div class="eyebrow" style="margin-bottom:12px;">{{ __('coming_soon.eyebrow') }}</div>
            <h1 class="page-title" style="font-size:30px;margin-bottom:10px;">{{ __('coming_soon.heading') }}</h1>

            @if ($maskedEmail)
                <p class="lede">{{ __('coming_soon.on_list') }}</p>
                <div class="soon-number" id="waitlist-email">
                    <svg width="18" height="18" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2.5 8.2l3.4 3.3 7.6-7.5" stroke="#2D2D2D" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span>
                        <span class="num">{{ $maskedEmail }}</span>
                        <span class="note">{{ __('coming_soon.on_list_note') }}</span>
                    </span>
                </div>
            @else
                <p class="lede">{{ __('coming_soon.leave_email') }}</p>
                <div class="soon-alt" style="margin-top:20px;padding-top:0;border-top:none;">
                    <a href="{{ route('login') }}" id="waitlist-back">{{ __('coming_soon.join') }}</a>
                </div>
            @endif

            <a class="btn-dark btn-cta soon-home" id="soon-home-link" href="{{ config('membership.home_url') }}">{{ __('coming_soon.home') }}</a>

            <div class="soon-alt">
                <a href="{{ route('membership.pricing') }}" id="soon-pricing-link">{{ __('coming_soon.whats_coming') }}</a>
            </div>

            <p class="soon-legal">
                {!! __('coming_soon.legal', [
                    'privacy' => '<a href="'.e(config('legal.base_url')).'/privacy-policy" target="_blank" rel="noopener">'.e(__('coming_soon.privacy')).'</a>',
                ]) !!}
            </p>

            @include('partials.locale-switcher', ['id' => 'soon-locale'])
        </div>
    </div>
@endsection

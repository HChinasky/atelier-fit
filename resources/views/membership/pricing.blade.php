@extends('membership.layout')

@section('title', __('pages.pricing.title'))

@section('styles')
        /* ---- Pricing page ---- */
        .pricing-page { position: relative; min-height: 100vh; padding: 44px 22px 56px;
            background: radial-gradient(120% 60% at 50% 0%, #FFFFFF, #F0F0F0); }
        /* Way back for a signed-in member (guests see the public page as drawn). */
        .pricing-nav { position: absolute; top: 22px; inset-inline-start: 22px; display: flex; align-items: center; gap: 7px;
            font-size: 11px; letter-spacing: .16em; color: #6B6B6B; text-transform: uppercase;
            text-decoration: none; transition: color .3s ease; }
        .pricing-nav:hover { color: var(--ink-2); }
        @media (min-width: 834px) { .pricing-page { padding: 44px; } }
        .pricing-head { text-align: center; padding: 2px 24px 6px; animation: nrise .6s var(--ease) both; }
        .pricing-head .brand-logo { display: block; margin: 0 auto 14px; height: 34px; width: auto; }
        .pricing-head h1 { font-family: 'Playfair Display', serif; font-weight: 500; font-size: 40px;
            line-height: 1.02; color: var(--ink-2); margin: 0; }
        @media (min-width: 834px) { .pricing-head h1 { font-size: 52px; } }
        @media (min-width: 1280px) { .pricing-head h1 { font-size: 60px; } }
        .pricing-head .lede { margin: 12px auto 0; max-width: 44ch; }
        .pricing-head .coming-soon { margin: 16px auto 0; max-width: 52ch; padding: 11px 16px;
            background: #F0F0F0; border: 1px solid rgba(45, 45, 45, .18); border-radius: 0;
            font-weight: 300; font-size: 12px; line-height: 1.55; color: #2D2D2D; }
        .pricing-head .coming-soon strong { font-weight: 500; }

        .tier-grid { display: grid; grid-template-columns: 1fr; gap: 18px; margin: 30px auto 0;
            max-width: 1180px; align-items: stretch; }
        @media (min-width: 834px) { .tier-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 1280px) { .tier-grid { grid-template-columns: repeat(4, 1fr); gap: 16px; margin-top: 38px; } }

        .tier-card { position: relative; display: flex; flex-direction: column; border-radius: 0;
            padding: 26px 22px 24px; background: #FFFFFF; border: 1px solid rgba(45, 45, 45, .12);
            box-shadow: 0 1px 2px rgba(45, 45, 45, .04); animation: nrise .55s var(--ease) both; }
        .tier-card.popular { padding-top: 30px; border: 1px solid var(--ink-2);
            box-shadow: 0 22px 46px -22px rgba(107, 107, 107, .5); transform: translateY(-6px); }
        .tier-card.vip { background: radial-gradient(circle at 50% 0%, #2D2D2D, #2D2D2D);
            border: 1px solid rgba(107, 107, 107, .5); box-shadow: 0 22px 46px -22px rgba(45, 45, 45, .5); }
        .tier-badge { position: absolute; top: -11px; left: 50%; transform: translateX(-50%);
            background: var(--ink-2); color: var(--surface); font-size: 8.5px; letter-spacing: .18em;
            text-transform: uppercase; padding: 5px 14px; border-radius: 0; white-space: nowrap; }
        .tier-flair { position: absolute; top: 16px; inset-inline-end: 16px; font-family: 'Playfair Display', serif;
            font-size: 20px; color: rgba(255, 255, 255, .5); }
        .tier-name { font-size: 10px; letter-spacing: .26em; color: var(--gold-dark);
            text-transform: uppercase; margin-bottom: 14px; }
        .tier-card.vip .tier-name { color: rgba(255, 255, 255, .6); }
        .tier-price { display: flex; align-items: baseline; gap: 4px; margin-bottom: 4px; }
        .tier-price .amount { font-family: 'Playfair Display', serif; font-size: 38px; color: var(--ink-2); line-height: 1; }
        .tier-card.vip .tier-price .amount { color: var(--surface); }
        .tier-price .per { font-size: 12px; color: #6B6B6B; }
        .tier-card.vip .tier-price .per { color: rgba(255, 255, 255, .5); }
        .tier-tokens { font-size: 11px; letter-spacing: .04em; color: var(--gold-dark); margin-bottom: 20px; }
        .tier-card.vip .tier-tokens { color: rgba(255, 255, 255, .6); }
        .tier-rule { height: 1px; background: rgba(45, 45, 45, .1); margin-bottom: 18px; }
        .tier-card.vip .tier-rule { background: rgba(107, 107, 107, .25); }
        .tier-features { display: flex; flex-direction: column; gap: 12px; flex: 1; }
        .tier-feature { display: flex; gap: 10px; align-items: flex-start; }
        .tier-feature .check { flex: none; margin-top: 3px; color: var(--gold-dark); }
        .tier-card.vip .tier-feature .check { color: rgba(255, 255, 255, .7); }
        .tier-feature span:last-child { font-weight: 300; font-size: 12.5px; line-height: 1.5; color: #6B6B6B; }
        .tier-card.vip .tier-feature span:last-child { color: rgba(255, 255, 255, .82); }
        .tier-btn { margin-top: 22px; padding: 14px; border-radius: 0; cursor: pointer; font-size: 10.5px;
            font-weight: 500; letter-spacing: .16em; text-transform: uppercase;
            transition: all .3s var(--ease); border: 1px solid rgba(45, 45, 45, .22);
            background: transparent; color: #6B6B6B; }
        .tier-btn:hover { border-color: var(--ink-2); color: var(--ink-2); }
        .tier-card.popular .tier-btn { border: none; background: var(--ink-2); color: var(--surface); }
        .tier-card.popular .tier-btn:hover { background: var(--ink); transform: translateY(-2px); }
        .tier-card.vip .tier-btn { border: 1px solid var(--surface);
            background: var(--surface); color: var(--ink); }
        .tier-card.vip .tier-btn:hover { transform: translateY(-2px);
            box-shadow: 0 16px 30px -14px rgba(0, 0, 0, .5); }

        .pricing-trust { display: flex; align-items: center; justify-content: center; gap: 10px;
            margin-top: 26px; padding: 0 24px; flex-wrap: wrap; }
        .pricing-trust span { font-weight: 300; font-size: 11.5px; letter-spacing: .04em; color: var(--muted-2); }

        .pricing-terms { max-width: 720px; margin: 34px auto 0; padding: 22px 24px; background: var(--surface);
            border: 1px solid rgba(45, 45, 45, .16); }
        .pricing-terms h2 { font-size: 11px; letter-spacing: .2em; text-transform: uppercase; font-weight: 500;
            margin: 0 0 12px; color: var(--ink-2); }
        .pricing-terms ul { margin: 0; padding-inline-start: 18px; }
        .pricing-terms li { font-weight: 300; font-size: 12.5px; line-height: 1.55; color: #6B6B6B; margin: 5px 0; }
        .pricing-terms-links { margin: 14px 0 0; font-size: 12px; line-height: 1.6; color: #6B6B6B; }
        .pricing-terms-links a { color: var(--ink-2); text-decoration: underline; text-underline-offset: 2px; }
@endsection

@section('content')
    <div class="pricing-page">
        @auth
            <a class="pricing-nav" href="{{ route('membership.account.dashboard') }}" id="pricing-back-to-account">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M10 3l-5 5 5 5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                {{ __('pages.pricing.back') }}
            </a>
        @endauth
        <div class="pricing-head">
            <img class="brand-logo" src="{{ asset('Logo_dark.svg') }}" alt="{{ __('common.logo_alt') }}">
            <h1>{{ __('pages.pricing.heading') }}</h1>
            <p class="lede">{{ __('pages.pricing.lede') }}</p>

            {{-- The plans below are a preview: no payment provider is connected and
                 nothing on this page can be bought. Saying so on the page itself,
                 not only in the Terms, is the point — prices shown without it read
                 as an offer, and an offer that cannot be accepted is misleading. --}}
            <p class="coming-soon" role="status">
                <strong>{{ __('pages.pricing.soon_strong') }}</strong> {{ __('pages.pricing.soon_text') }}
            </p>
        </div>

        <div class="tier-grid">
            @foreach ($tiers as $tier)
                <div class="tier-card {{ $tier['badge'] ? 'popular' : '' }} {{ $tier['vip'] ? 'vip' : '' }}">
                    @if ($tier['badge'])
                        <div class="tier-badge">{{ __('pages.pricing.popular') }}</div>
                    @endif
                    @if ($tier['vip'])
                        <div class="tier-flair">⚜</div>
                    @endif
                    <div class="tier-name">{{ $tier['name'] }}</div>
                    <div class="tier-price">
                        <span class="amount">{{ $tier['price'] }}</span>
                        <span class="per">{{ __('pages.pricing.per_month') }}</span>
                    </div>
                    <div class="tier-tokens">{{ $tier['tokens'] }}</div>
                    <div class="tier-rule"></div>
                    <div class="tier-features">
                        @foreach ($tier['features'] as $feature)
                            <div class="tier-feature">
                                <span class="check"><svg width="13" height="13" viewBox="0 0 13 13" fill="none"><path d="M2.5 6.6l2.4 2.4 5-5.4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                                <span>{{ $feature }}</span>
                            </div>
                        @endforeach
                    </div>
                    {{-- Inert until payments actually exist. A live-looking "Choose
                         plan" button opening a payment sheet that takes no money is
                         the misleading part, not the price itself. --}}
                    <button class="tier-btn" type="button" disabled aria-disabled="true">
                        {{ __('pages.pricing.soon_button') }}
                    </button>
                </div>
            @endforeach
        </div>

        <div class="pricing-trust">
            <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><rect x="3.4" y="7.2" width="9.2" height="6.2" rx="1.5" stroke="#6B6B6B" stroke-width="1.1"/><path d="M5.5 7.2V5.4a2.5 2.5 0 015 0V7.2" stroke="#6B6B6B" stroke-width="1.1"/></svg>
            {{-- Describes the intended payment methods, in the future tense: none of
                 this is live, and the present tense would claim a capability the
                 service does not have. --}}
            <span>{{ __('pages.pricing.trust') }}</span>
        </div>

        {{-- The subscription terms are shown before anyone could pay, not only in
             the Terms: auto-renewal, expiring try-ons and the price basis are what
             a buyer needs to know up front. Links to both policies and a contact
             sit here, next to the (still inert) plan buttons. --}}
        <section class="pricing-terms" id="pricing-terms" aria-labelledby="pricing-terms-h">
            <h2 id="pricing-terms-h">{{ __('pages.pricing.terms_heading') }}</h2>
            <ul>
                <li>{{ __('pages.pricing.prices_aed') }}
                    @if (config('membership.prices_include_vat') === true) {{ __('pages.pricing.vat_included') }}
                    @elseif (config('membership.prices_include_vat') === false) {{ __('pages.pricing.vat_excluded') }}
                    @endif
                </li>
                <li>{{ __('pages.pricing.terms_billing') }}</li>
                <li>{{ __('pages.pricing.terms_credits') }}</li>
                <li>{{ __('pages.pricing.terms_failed') }}</li>
                <li>{{ __('pages.pricing.terms_vip') }}</li>
                <li>{{ __('pages.pricing.terms_ai') }}</li>
            </ul>
            <p class="pricing-terms-links" id="pricing-legal-links">
                {{ __('pages.pricing.links_lead') }}
                <a href="{{ config('legal.base_url') }}/terms-of-use" target="_blank" rel="noopener">{{ __('pages.pricing.link_terms') }}</a>
                {{ __('pages.pricing.links_and') }}
                <a href="{{ config('legal.base_url') }}/refund-policy" target="_blank" rel="noopener">{{ __('pages.pricing.link_refund') }}</a>.
                {{ __('pages.pricing.contact') }}
                <a href="mailto:{{ config('legal.privacy_email') }}">{{ config('legal.privacy_email') }}</a>
            </p>
        </section>

        @include('partials.locale-switcher', ['id' => 'pricing-locale', 'top' => '28px'])
    </div>
@endsection

{{-- Membership overlays (Stage A: markup + front-side states only, nothing is
     sent anywhere). --}}

{{-- ============ GATE: "Loved the look?" (after 2 guest try-ons) ============ --}}
<div class="modal-backdrop" x-cloak x-show="modal === 'gate'" id="gate-modal">
    <div class="modal-scrim" x-on:click="closeModal()"></div>
    <div class="modal-card">
        <button class="modal-close" x-on:click="closeModal()" aria-label="{{ __('common.close') }}" id="gate-close">
            <svg width="11" height="11" viewBox="0 0 11 11" fill="none"><path d="M1 1l9 9M10 1l-9 9" stroke="#6B6B6B" stroke-width="1.2" stroke-linecap="round"/></svg>
        </button>
        <div class="modal-handle"></div>
        <div style="text-align:center;">
            <div class="eyebrow" style="margin-bottom:14px;">{{ __('modals.gate.eyebrow') }}</div>
            <h2>{{ __('modals.gate.title') }}</h2>
            <p class="lede" style="margin:0 auto 24px;max-width:32ch;">{!! __('modals.gate.lede', [
                'free' => '<strong style="font-weight:500;color:var(--ink-2);">'.e(__('modals.gate.free')).'</strong>',
                'more' => '<strong style="font-weight:500;color:var(--ink-2);">'.e(__('modals.gate.more')).'</strong>',
            ]) !!}</p>
        </div>
        <button class="btn-dark" style="width:100%;display:flex;align-items:center;justify-content:center;gap:10px;" x-on:click="openModal('email')">
            <svg width="17" height="17" viewBox="0 0 16 16" fill="none"><rect x="2" y="3.5" width="12" height="9" rx="1.2" stroke="#FFFFFF" stroke-width="1.3"/><path d="M2.5 4.5L8 8.6l5.5-4.1" stroke="#FFFFFF" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
            {{ __('modals.gate.email') }}
        </button>
        @foreach (\App\Services\Auth\SocialProviders::enabled() as $provider)
            <a class="btn-quiet" style="display:block;width:100%;margin-top:12px;font-size:11.5px;letter-spacing:.1em;color:var(--muted);text-align:center;text-decoration:none;" href="{{ route('auth.social.redirect', $provider) }}">{{ __('login.email.continue_with', ['provider' => ucfirst($provider)]) }}</a>
        @endforeach
        <div class="modal-privacy">
            <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><rect x="3.4" y="7.2" width="9.2" height="6.2" rx="1.5" stroke="#6B6B6B" stroke-width="1.1"/><path d="M5.5 7.2V5.4a2.5 2.5 0 015 0V7.2" stroke="#6B6B6B" stroke-width="1.1"/></svg>
            <span>{{ __('common.privacy_note') }}</span>
        </div>
    </div>
</div>

{{-- ============ E-MAIL ENTRY ============ --}}
<div class="auth-screen" x-cloak x-show="modal === 'email'" id="email-modal">
    <button class="auth-close" x-on:click="closeModal()" aria-label="{{ __('common.close') }}" id="email-close">
        <svg width="11" height="11" viewBox="0 0 11 11" fill="none"><path d="M1 1l9 9M10 1l-9 9" stroke="#6B6B6B" stroke-width="1.2" stroke-linecap="round"/></svg>
    </button>
    <div class="auth-card">
        <button class="auth-back" x-on:click="openModal('gate')">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M10 3l-5 5 5 5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
            {{ __('common.back') }}
        </button>
        @include('membership.partials.auth-email-step')
    </div>
</div>

{{-- ============ OTP (6 cells, resend timer, error / blocked states) ============ --}}
<div class="auth-screen" x-cloak x-show="modal === 'otp'" id="otp-modal">
    <button class="auth-close" x-on:click="closeModal()" aria-label="{{ __('common.close') }}" id="otp-close">
        <svg width="11" height="11" viewBox="0 0 11 11" fill="none"><path d="M1 1l9 9M10 1l-9 9" stroke="#6B6B6B" stroke-width="1.2" stroke-linecap="round"/></svg>
    </button>
    <div class="auth-card">
        <button class="auth-back" x-on:click="openModal('email')">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M10 3l-5 5 5 5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
            {{ __('common.back') }}
        </button>
        @include('membership.partials.auth-otp-step')
    </div>
</div>

{{-- ============ PAYWALL: "Your complimentary try-ons are over" — with the plans ============ --}}
{{-- The plans are a preview: no payment provider is connected, so the buttons are
     inert and the note says so, exactly as /pricing does. A live "Choose" button here
     would be an offer that cannot be accepted. --}}
<div class="modal-backdrop" x-cloak x-show="modal === 'paywall'" id="paywall-modal">
    <div class="modal-scrim" x-on:click="closeModal()"></div>
    <div class="modal-card plans-card">
        <div class="modal-handle"></div>
        <div style="text-align:center;">
            <div style="width:52px;height:52px;border-radius:50%;border:1px solid rgba(107,107,107,.45);display:flex;align-items:center;justify-content:center;margin:0 auto 18px;"><span style="font-family:'Playfair Display',serif;font-size:24px;color:var(--gold);">✦</span></div>
            <h2 style="font-size:27px;line-height:1.14;">{!! __('modals.paywall.title') !!}</h2>
            <p class="lede" style="margin:0 auto 24px;max-width:36ch;">{{ __('modals.paywall.lede') }}</p>
        </div>

        <div class="plan-mini-grid" id="paywall-plans">
            @foreach ($paywallTiers as $tier)
                <div class="plan-mini {{ $tier['badge'] ? 'popular' : '' }} {{ $tier['vip'] ? 'vip' : '' }}" data-plan="{{ $tier['key'] }}">
                    @if ($tier['badge'])
                        <span class="badge">{{ __('pages.pricing.popular') }}</span>
                    @endif
                    <span class="name">{{ $tier['name'] }}</span>
                    <span class="price">{{ $tier['price'] }} <small>{{ __('pages.pricing.per_month') }}</small></span>
                    <span class="tokens">{{ $tier['tokens'] }}</span>
                    <button class="plan-mini-btn" type="button" disabled aria-disabled="true">{{ __('pages.pricing.soon_button') }}</button>
                </div>
            @endforeach
        </div>

        <p class="plan-soon" id="paywall-soon" role="status"><strong>{{ __('pages.pricing.soon_strong') }}</strong> {{ __('pages.pricing.soon_text') }}</p>

        <a class="btn-dark" style="display:block;width:100%;text-align:center;text-decoration:none;" href="{{ route('membership.pricing') }}">{{ __('modals.paywall.cta') }}</a>
        <button class="btn-quiet" style="width:100%;margin-top:12px;" x-on:click="closeModal()">{{ __('modals.paywall.later') }}</button>
    </div>
</div>

{{-- ============ PAYMENT: Apple Pay + embedded-provider placeholder ============ --}}
<div class="modal-backdrop" x-cloak x-show="modal === 'payment'" id="payment-modal">
    <div class="modal-scrim" x-on:click="closeModal()"></div>
    <div class="modal-card">
        <button class="modal-close" x-on:click="closeModal()" aria-label="{{ __('common.close') }}">
            <svg width="11" height="11" viewBox="0 0 11 11" fill="none"><path d="M1 1l9 9M10 1l-9 9" stroke="#6B6B6B" stroke-width="1.2" stroke-linecap="round"/></svg>
        </button>
        <div class="modal-handle"></div>
        <div class="eyebrow" style="margin-bottom:10px;">{{ __('modals.payment.eyebrow') }}</div>
        <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:22px;">
            <h2 style="font-size:24px;margin:0;">Shopaholic</h2>
            <span style="font-size:15px;color:var(--ink-2);">AED 99<span style="font-size:11px;color:#6B6B6B;">{{ __('modals.payment.per_month') }}</span></span>
        </div>
        <button class="btn-applepay" type="button">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="#fff"><path d="M11 2.2c.1.9-.3 1.7-.8 2.3-.5.6-1.4 1.1-2.2 1-.1-.9.3-1.8.8-2.3.6-.6 1.5-1 2.2-1zM12.8 11.4c-.3.8-.5 1.1-.9 1.8-.6.9-1.4 2-2.5 2-.9 0-1.2-.6-2.3-.6s-1.4.6-2.3.6c-1 0-1.8-1-2.4-1.9C.8 11.2.7 8.3 1.9 6.8c.8-1 1.9-1.3 2.6-1.3.9 0 1.5.6 2.2.6.7 0 1.1-.6 2.2-.6.7 0 1.6.3 2.3 1.1-2 .9-1.7 3.8.6 4.8z"/></svg>
            {{ __('modals.payment.apple_pay') }}
        </button>
        <div class="pay-divider"><span></span><span class="label">{{ __('modals.payment.or_card') }}</span><span></span></div>
        {{-- Placeholder frame: the payment provider's OWN embedded fields
             (Stripe Elements / Checkout.com) mount here in the billing phase.
             We never render card inputs of our own. --}}
        <div class="pay-frame">
            <div class="frame-head"><span>{{ __('modals.payment.card_field') }}</span><span>Stripe / Checkout.com</span></div>
            <div class="pay-shimmer" style="margin-bottom:10px;"></div>
            <div style="display:flex;gap:10px;"><div class="pay-shimmer" style="flex:1;"></div><div class="pay-shimmer" style="flex:1;"></div></div>
        </div>
        <a class="btn-dark" style="display:block;width:100%;margin-top:18px;text-align:center;text-decoration:none;" href="{{ route('membership.account.dashboard') }}">{{ __('modals.payment.cta') }}</a>
        <p style="font-weight:300;font-size:11px;line-height:1.5;color:var(--muted-2);text-align:center;margin:13px 0 0;">{{ __('modals.payment.cancel') }}</p>
    </div>
</div>

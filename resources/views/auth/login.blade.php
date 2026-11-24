@extends('membership.layout')

@section('title', __('login.title'))

{{-- The landing page IS the sign-in page: passwordless — a code by e-mail, or
     Google / Apple — the same two steps as the overlays (partials/auth-email-step,
     auth-otp-step) driven by the same membership() Alpine component. `modal`
     doubles as the step here — the overlays themselves are excluded (see
     $withoutOverlays). --}}

@section('styles')
        /* ---- Login page ---- */
        .login-page { min-height: 100vh; display: flex; align-items: flex-start; justify-content: center;
            padding: 44px 26px 56px; background: radial-gradient(120% 80% at 50% 0%, #FFFFFF, #F0F0F0); }
        @media (min-width: 768px) { .login-page { align-items: center; padding: 40px; } }
        .login-card { width: 100%; max-width: 420px; animation: nrise .55s var(--ease) both; }
        @media (min-width: 768px) {
            .login-card { width: 440px; max-width: 100%; background: var(--surface);
                border: 1px solid rgba(45, 45, 45, .08); border-radius: 0; padding: 40px;
                box-shadow: 0 30px 70px -34px rgba(45, 45, 45, .4); }
        }
        .login-brand { display: block; height: 34px; width: auto; margin: 0 auto 30px; }
        .login-purpose { margin: 0 0 24px; text-align: center; font-weight: 300; font-size: 12.5px;
            line-height: 1.65; color: var(--muted-2); }
        .login-alt { margin-top: 26px; padding-top: 20px; border-top: 1px solid rgba(45, 45, 45, .08);
            display: flex; flex-direction: column; align-items: center; gap: 12px; text-align: center; }
        .login-alt a { font-size: 11px; letter-spacing: .16em; text-transform: uppercase;
            text-decoration: none; color: #6B6B6B; transition: color .3s ease; }
        .login-alt a:hover { color: var(--ink-2); }
        .login-privacy { display: flex; align-items: center; gap: 8px; justify-content: center; margin-top: 18px; }
        .login-privacy span { font-weight: 300; font-size: 10.5px; letter-spacing: .03em; color: var(--muted-2); }
        /* Notice line under the privacy marker — quieter than the sign-in copy,
           still legible; links carry the underline so they read as links. */
        .login-legal { margin: 10px auto 0; max-width: 34ch; text-align: center; font-weight: 300;
            font-size: 10.5px; line-height: 1.6; letter-spacing: .02em; color: var(--muted-2); }
        .login-legal a { color: var(--muted-2); text-decoration: underline; }
        .login-legal a:hover { color: var(--ink-2); }
@endsection

@section('content')
    <div class="login-page">
        <div class="login-card">
            <img class="login-brand" src="{{ asset('Logo_dark.svg') }}" alt="{{ __('common.logo_alt') }}">

            {{-- What the service is, in plain words, for people and for Google's
                 OAuth reviewers: the purpose, what sign-in is used for, and what
                 the service does not do. --}}
            <p class="login-purpose">{{ __('login.page.purpose') }}</p>

            {{-- Step one: the address, or a provider. The copy reads as a
                 sign-in either way: while the pre-launch curtain is up the same
                 field still keeps the address for the waiting list and hands
                 off to /coming-soon. --}}
            <div x-show="modal !== 'otp'" id="login-email-step">
                @include('membership.partials.auth-email-step', [
                    'eyebrow' => __('login.page.eyebrow'),
                    'title' => __('login.page.title'),
                    'lede' => __('login.page.lede'),
                    'cta' => __('login.page.cta'),
                ])
            </div>

            {{-- Step two: the code. --}}
            <div x-cloak x-show="modal === 'otp'" id="login-otp-step">
                <button class="auth-back" x-on:click="openModal('email')">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M10 3l-5 5 5 5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    {{ __('common.back') }}
                </button>
                @include('membership.partials.auth-otp-step', [
                    'eyebrow' => __('login.page.otp_eyebrow'),
                    'ledeBefore' => __('login.page.otp_lede_before'),
                    'cta' => __('login.page.otp_cta'),
                ])
            </div>

            {{-- No guest entry here: the try-on page is an internal testing
                 surface behind FEATURE_FITTING_ROOM, so the only way on from
                 this page is signing in. --}}
            <div class="login-alt">
                <a href="{{ route('membership.pricing') }}" id="login-pricing-link">{{ __('login.page.pricing_link') }}</a>
            </div>

            <div class="login-privacy">
                <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><rect x="3.4" y="7.2" width="9.2" height="6.2" rx="1.5" stroke="#6B6B6B" stroke-width="1.1"/><path d="M5.5 7.2V5.4a2.5 2.5 0 015 0V7.2" stroke="#6B6B6B" stroke-width="1.1"/></svg>
                <span>{{ __('common.privacy_note') }}</span>
            </div>

            {{-- A notice, not a consent box. Signing in with an e-mail address
                 is necessary to provide the service, so asking permission for
                 it would be asking for something nobody can refuse — which is
                 what makes a tick box here meaningless. The Terms are accepted
                 by use; the Privacy Policy is read, not agreed to (see Terms
                 §2). The two things that DO need explicit consent — the photo
                 and marketing — have their own boxes where they belong. --}}
            <p class="login-legal">
                {!! __('login.page.legal', [
                    'terms' => '<a href="'.e(route('legal.show', 'terms-of-use')).'" target="_blank" rel="noopener">'.e(__('login.page.terms')).'</a>',
                    'privacy' => '<a href="'.e(route('legal.show', 'privacy-policy')).'" target="_blank" rel="noopener">'.e(__('login.page.privacy')).'</a>',
                ]) !!}
            </p>

            @include('partials.locale-switcher', ['id' => 'login-locale'])
        </div>
    </div>
@endsection

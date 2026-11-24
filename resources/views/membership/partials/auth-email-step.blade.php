{{-- E-mail entry — the body of step one, shared by the email overlay
     (partials/modals) and the sign-in page. Copy is overridable so each host
     can frame the step ("Step One of Two" vs "Sign In").

     Google and Apple sit under the field as alternatives; each button is
     drawn only when its provider is configured (SocialProviders). Both are
     plain links to the redirect route — no provider script runs on the page. --}}

@php($socialProviders = \App\Services\Auth\SocialProviders::enabled())

<div class="eyebrow" style="margin-bottom:12px;">{{ $eyebrow ?? __('login.email.eyebrow') }}</div>
<h2 class="page-title" style="font-size:30px;margin-bottom:10px;">{{ $title ?? __('login.email.title') }}</h2>
<p class="lede" style="margin:0 0 26px;max-width:34ch;">{{ $lede ?? __('login.email.lede') }}</p>

@if (session('auth_error'))
    {{-- A provider sign-in that did not complete lands back here with one line. --}}
    <div class="field-error" style="margin:0 0 16px;" id="auth-error">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.4" stroke="#A6432E" stroke-width="1.1"/><path d="M8 4.8v3.4" stroke="#A6432E" stroke-width="1.2" stroke-linecap="round"/><circle cx="8" cy="10.9" r=".8" fill="#A6432E"/></svg>
        <span>{{ session('auth_error') }}</span>
    </div>
@endif

<div class="email-field" x-bind:class="{ error: emailError }">
    <input class="email-input" type="email" inputmode="email" autocomplete="email" autocapitalize="off" spellcheck="false"
           placeholder="{{ __('login.email.placeholder') }}" maxlength="254" aria-label="{{ __('login.email.label') }}"
           x-model="email" x-on:input="emailError = false" x-on:keydown.enter="sendCode()">
</div>
<div class="field-error" x-cloak x-show="emailError">
    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.4" stroke="#A6432E" stroke-width="1.1"/><path d="M8 4.8v3.4" stroke="#A6432E" stroke-width="1.2" stroke-linecap="round"/><circle cx="8" cy="10.9" r=".8" fill="#A6432E"/></svg>
    <span x-text="emailErrorText">{{ __('login.email.invalid') }}</span>
</div>

<button class="btn-dark btn-cta" x-bind:disabled="email.trim().length === 0 || sending"
        x-on:click="sendCode()" x-text="sending ? @jsText(__('login.email.sending')) : @jsText($cta ?? __('login.email.cta'))">{{ $cta ?? __('login.email.cta') }}</button>

@if ($socialProviders !== [])
    <div class="auth-divider"><span></span><span class="label">{{ __('login.email.or') }}</span><span></span></div>

    <div class="auth-providers">
        @if (in_array('google', $socialProviders, true))
            <a class="btn-provider" id="auth-google" href="{{ route('auth.social.redirect', 'google') }}">
                {{-- Monochrome "G": the site is four greys and Google's brand rules allow a one-colour mark. --}}
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21.6 12.23c0-.68-.06-1.33-.17-1.96H12v3.7h5.38a4.6 4.6 0 01-2 3.02v2.5h3.24c1.9-1.74 2.98-4.31 2.98-7.26z"/><path d="M12 22c2.7 0 4.97-.9 6.62-2.42l-3.24-2.5c-.9.6-2.04.95-3.38.95-2.6 0-4.8-1.75-5.59-4.1H3.07v2.57A10 10 0 0012 22z"/><path d="M6.41 13.92A6 6 0 016.1 12c0-.67.11-1.31.31-1.92V7.5H3.07A10 10 0 002 12c0 1.61.39 3.14 1.07 4.5l3.34-2.58z"/><path d="M12 5.98c1.47 0 2.79.5 3.83 1.5l2.87-2.88C16.97 2.99 14.7 2 12 2a10 10 0 00-8.93 5.5l3.34 2.58C7.2 7.73 9.4 5.98 12 5.98z"/></svg>
                {{ __('login.email.continue_with', ['provider' => 'Google']) }}
            </a>
        @endif
        @if (in_array('apple', $socialProviders, true))
            <a class="btn-provider" id="auth-apple" href="{{ route('auth.social.redirect', 'apple') }}">
                <svg width="18" height="18" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M11 2.2c.1.9-.3 1.7-.8 2.3-.5.6-1.4 1.1-2.2 1-.1-.9.3-1.8.8-2.3.6-.6 1.5-1 2.2-1zM12.8 11.4c-.3.8-.5 1.1-.9 1.8-.6.9-1.4 2-2.5 2-.9 0-1.2-.6-2.3-.6s-1.4.6-2.3.6c-1 0-1.8-1-2.4-1.9C.8 11.2.7 8.3 1.9 6.8c.8-1 1.9-1.3 2.6-1.3.9 0 1.5.6 2.2.6.7 0 1.1-.6 2.2-.6.7 0 1.6.3 2.3 1.1-2 .9-1.7 3.8.6 4.8z"/></svg>
                {{ __('login.email.continue_with', ['provider' => 'Apple']) }}
            </a>
        @endif
    </div>
@endif

@if (config('services.turnstile.site_key'))
    {{-- Cloudflare Turnstile — its token is verified server-side before
         any code is sent (off in local; see TurnstileVerifier). --}}
    <div style="display:flex;justify-content:center;margin-top:22px;">
        <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}"></div>
    </div>
@endif

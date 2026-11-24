{{-- OTP entry (6 cells, resend timer, error / blocked states) — the body of step
     two, shared by the otp overlay (partials/modals) and the sign-in page. --}}

<div class="eyebrow" style="margin-bottom:12px;">{{ $eyebrow ?? __('login.otp.eyebrow') }}</div>
<h2 class="page-title" style="font-size:30px;margin-bottom:10px;">{{ $title ?? __('login.otp.title') }}</h2>
<p class="lede" style="margin:0 0 28px;max-width:34ch;">{{ $ledeBefore ?? __('login.otp.lede_before') }} <strong style="font-weight:500;color:var(--ink-2);overflow-wrap:anywhere;" x-text="emailDisplay()"></strong>{{ $ledeAfter ?? __('login.otp.lede_after') }}</p>

{{-- Blocked: too many attempts --}}
<div class="otp-locked" x-cloak x-show="otpBlocked">
    <div class="mark"><svg width="18" height="18" viewBox="0 0 16 16" fill="none"><rect x="3.4" y="7.2" width="9.2" height="6.2" rx="1.5" stroke="#A6432E" stroke-width="1.2"/><path d="M5.5 7.2V5.4a2.5 2.5 0 015 0V7.2" stroke="#A6432E" stroke-width="1.2"/></svg></div>
    <h3>{{ __('login.otp.blocked_title') }}</h3>
    <p>{!! __('login.otp.blocked_text', ['wait' => '<strong style="font-weight:500;color:var(--danger);">'.e(__('login.otp.blocked_wait')).'</strong>']) !!}</p>
</div>

<div x-show="! otpBlocked">
    <div class="otp-row">
        <template x-for="i in 6" :key="i">
            <div class="otp-cell"
                 x-bind:class="{ error: otpError, focus: ! otpError && otpCode.length === i - 1 }">
                <span x-text="otpCode[i - 1] || ''"></span>
                <span class="caret" x-show="! otpError && otpCode.length === i - 1"></span>
            </div>
        </template>
        {{-- One real input over the cells captures the digits. --}}
        <input class="otp-hidden" type="tel" inputmode="numeric" autocomplete="one-time-code"
               maxlength="6" aria-label="{{ __('login.otp.label') }}" x-on:input="onOtpInput($event)">
    </div>
    <div class="field-error" style="margin-top:14px;" x-cloak x-show="otpError">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.4" stroke="#A6432E" stroke-width="1.1"/><path d="M8 4.8v3.4" stroke="#A6432E" stroke-width="1.2" stroke-linecap="round"/><circle cx="8" cy="10.9" r=".8" fill="#A6432E"/></svg>
        <span x-text="otpErrorText">{{ __('login.otp.invalid_last') }}</span>
    </div>
    <div style="margin-top:22px;text-align:center;">
        <span x-show="resendLeft > 0" style="font-size:12.5px;letter-spacing:.03em;color:#6B6B6B;">{{ __('login.otp.resend_in') }} <span style="color:var(--muted);" x-text="resendLabel()"></span></span>
        <button class="resend-link" x-cloak x-show="resendLeft === 0" x-on:click="resend()">{{ __('login.otp.resend') }}</button>
    </div>
    <button class="btn-dark btn-cta" x-bind:disabled="otpCode.length !== 6 || verifying"
            x-on:click="verifyCode()" x-text="verifying ? @jsText(__('login.otp.verifying')) : @jsText($cta ?? __('login.otp.cta'))">{{ $cta ?? __('login.otp.cta') }}</button>
</div>

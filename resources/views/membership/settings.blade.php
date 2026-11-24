@extends('membership.shell')

@section('title', __('pages.settings.title'))

@section('styles')
        .settings-wrap { max-width: 640px; }
        /* Phone only: the sections the five bottom tabs leave out. */
        .settings-more { display: flex; flex-direction: column; margin-bottom: 20px; border: 1px solid rgba(45, 45, 45, .1); }
        .settings-more a { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px;
            font-size: 14px; color: var(--ink-2); text-decoration: none; }
        .settings-more a + a { border-top: 1px solid rgba(45, 45, 45, .1); }
        .settings-more a:focus-visible { outline: 2px solid var(--ink-2); outline-offset: -2px; }
        .settings-caption { font-size: 10px; letter-spacing: .2em; color: #6B6B6B;
            text-transform: uppercase; margin-bottom: 16px; }

        /* Reference-photo slots (lock count depends on the plan) */
        .photo-slots { display: flex; gap: 14px; margin-bottom: 32px; }
        .photo-slot { position: relative; width: 92px; height: 118px; border-radius: 0; flex: none;
            border: 1px solid rgba(45, 45, 45, .14); display: flex; align-items: center; justify-content: center; }
        .photo-slot.filled { background: repeating-linear-gradient(45deg, #F0F0F0 0 7px, rgba(45, 45, 45, .05) 7px 14px); }
        .photo-slot.open { background: repeating-linear-gradient(135deg, #FFFFFF 0 8px, #F0F0F0 8px 16px); }
        .photo-slot.locked { background: #F0F0F0; }
        .photo-slot.is-active { border-color: var(--ink-2); box-shadow: 0 0 0 3px rgba(45, 45, 45, .12); }
        .photo-slot .check { position: absolute; top: 7px; inset-inline-end: 7px; width: 18px; height: 18px;
            border-radius: 50%; background: var(--ink-2); display: flex; align-items: center; justify-content: center; }
        .photo-upsell { margin: -22px 0 32px; font-weight: 300; font-size: 12px; color: var(--gold-dark); }
        .photo-upsell a { color: var(--gold-dark); }

        .settings-card { border: 1px solid rgba(45, 45, 45, .1); border-radius: 0; padding: 20px;
            background: #FFFFFF; margin-bottom: 16px; }
        .settings-card .settings-caption { margin-bottom: 12px; }
        .plan-row { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
        .plan-row .plan-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--ink-2); }
        .plan-row .plan-price { font-family: 'Montserrat', sans-serif; font-size: 12px; color: #6B6B6B; }
        .plan-row .actions { display: flex; gap: 10px; }
        .btn-change-plan { padding: 10px 18px; border: 1px solid rgba(45, 45, 45, .2); border-radius: 0;
            background: none; font-size: 10.5px; letter-spacing: .12em; color: #6B6B6B;
            text-transform: uppercase; cursor: pointer; text-decoration: none;
            transition: border-color .3s ease; }
        .btn-change-plan:hover { border-color: var(--ink-2); }
        .btn-cancel-plan { padding: 10px 18px; border: none; background: none; font-size: 10.5px;
            letter-spacing: .12em; color: #6B6B6B; text-transform: uppercase; cursor: pointer; }
        .avatar-row { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .avatar-preview { width: 84px; height: 84px; border-radius: 50%; flex: none; overflow: hidden;
            background: radial-gradient(circle at 30% 25%, #FFFFFF, rgba(107, 107, 107, .55) 70%, #2D2D2D); }
        .avatar-preview img { display: block; width: 100%; height: 100%; object-fit: cover; }
        .avatar-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .avatar-actions label.btn-change-plan { display: inline-block; }
        .avatar-note { margin: 12px 0 0; font-size: 13px; color: var(--ink-2); }
        .avatar-note.is-error { color: var(--danger); }
        .avatar-hint { margin-top: 12px; font-size: 12px; }
        .privacy-text { font-weight: 300; font-size: 13px; line-height: 1.6; color: #6B6B6B; margin: 0; }

        .btn-delete-account { width: 100%; padding: 15px; border: 1px solid rgba(166, 67, 46, .4);
            border-radius: 0; background: none; font-size: 11.5px; letter-spacing: .14em;
            color: var(--danger); text-transform: uppercase; cursor: pointer; transition: background .3s ease; }
        .btn-delete-account:hover { background: rgba(166, 67, 46, .06); }
@endsection

@section('section')
    <div class="section-rise settings-wrap" id="settings-section">
        <div class="eyebrow">{{ __('pages.settings.eyebrow') }}</div>
        <h1 class="page-title" style="margin-bottom:24px;">{{ __('pages.settings.heading') }}</h1>

        {{-- On a phone the bottom tabs carry five sections; the rest are here. --}}
        @php($moreSections = collect($navItems)->reject(fn ($item) => array_key_exists($item['key'], \App\Services\Membership\AccountShell::TAB_ICONS)))
        @if ($moreSections->isNotEmpty())
            <nav class="settings-more only-mobile" id="settings-more" aria-label="{{ __('pages.settings.more') }}">
                @foreach ($moreSections as $item)
                    <a href="{{ route($item['route'], ['plan' => $plan]) }}">
                        {{ ['looks' => __('pages.settings.more_looks'), 'vip' => __('pages.settings.more_vip')][$item['key']] ?? $item['label'] }}
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M6 3l5 5-5 5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                @endforeach
            </nav>
        @endif

        {{-- Profile picture: picking a file submits the form, no extra button. --}}
        <div class="settings-card" id="avatar-card">
            <div class="settings-caption">{{ __('pages.settings.avatar') }}</div>
            <div class="avatar-row">
                <span class="avatar-preview">@if ($avatarUrl)<img src="{{ $avatarUrl }}" alt="{{ __('pages.settings.avatar') }}">@endif</span>
                <div class="avatar-actions">
                    <form method="POST" action="{{ route('membership.account.avatar.update') }}" enctype="multipart/form-data" id="avatar-form">
                        @csrf
                        <label class="btn-change-plan">
                            {{ $avatarUrl ? __('pages.settings.avatar_change') : __('pages.settings.avatar_upload') }}
                            <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" hidden
                                   onchange="if (this.files.length) this.form.submit()">
                        </label>
                    </form>
                    @if ($avatarUrl)
                        <form method="POST" action="{{ route('membership.account.avatar.destroy') }}" id="avatar-remove-form">
                            @csrf
                            @method('DELETE')
                            <button class="btn-cancel-plan" type="submit">{{ __('pages.settings.avatar_remove') }}</button>
                        </form>
                    @endif
                </div>
            </div>
            @error('avatar')
                <p class="avatar-note is-error" role="alert">{{ $message }}</p>
            @enderror
            @if (session('avatar_status'))
                <p class="avatar-note" role="status">{{ session('avatar_status') }}</p>
            @endif
            {{-- Honest about retention: kept until removed, never sent to the AI. --}}
            <p class="privacy-text avatar-hint">{{ __('pages.settings.avatar_hint') }}</p>
        </div>

        <div class="settings-caption">{{ __('pages.settings.references') }}</div>
        <div class="photo-slots" id="photo-slots">
            @foreach ($photoSlots as $slot)
                <div class="photo-slot {{ $slot['locked'] ? 'locked' : ($slot['filled'] ? 'filled' : 'open') }} {{ $slot['active'] && $slot['filled'] ? 'is-active' : '' }}">
                    @if ($slot['filled'] && $slot['active'])
                        <span class="check"><svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M2.5 6.2l2.2 2.2 4.6-4.8" stroke="#FFFFFF" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                    @endif
                    @if ($slot['locked'])
                        <svg width="18" height="18" viewBox="0 0 16 16" fill="none"><rect x="3.4" y="7.2" width="9.2" height="6.2" rx="1.5" stroke="#6B6B6B" stroke-width="1.2"/><path d="M5.5 7.2V5.4a2.5 2.5 0 015 0V7.2" stroke="#6B6B6B" stroke-width="1.2"/></svg>
                    @endif
                </div>
            @endforeach
        </div>
        @if ($photoUpsell)
            <div class="photo-upsell">{{ __('pages.settings.upsell') }} <a href="{{ route('membership.pricing') }}">{{ __('pages.settings.upgrade') }}</a></div>
        @endif

        {{-- Only the dev plan mock has a plan to show; without billing a
             real member has none. --}}
        @if ($planData['name'])
            <div class="settings-card" id="membership-card">
                <div class="settings-caption">{{ __('pages.settings.membership') }}</div>
                <div class="plan-row">
                    <div class="plan-name">{{ $planData['name'] }} <span class="plan-price">· {{ $planData['price'] }}{{ __('modals.payment.per_month') }}</span></div>
                    <div class="actions">
                        <a class="btn-change-plan" href="{{ route('membership.pricing') }}">{{ __('pages.settings.change_plan') }}</a>
                        <button class="btn-cancel-plan" type="button">{{ __('pages.settings.cancel') }}</button>
                    </div>
                </div>
            </div>
        @endif

        <div class="settings-card" id="style-quiz-card">
            <div class="settings-caption">{{ __('pages.settings.quiz') }}</div>
            <div class="plan-row">
                <p class="privacy-text" style="margin:0;">{{ __('pages.settings.quiz_text') }}</p>
                <a class="btn-change-plan" href="{{ route('membership.account.onboarding', ['plan' => $plan]) }}">
                    {{ auth()->user()?->styleProfile?->completed_at ? __('pages.settings.quiz_edit') : __('pages.settings.quiz_take') }}
                </a>
            </div>
        </div>

        <div class="settings-card" id="language-card">
            <div class="settings-caption">{{ __('pages.settings.language') }}</div>
            <div class="plan-row">
                <p class="privacy-text" style="margin:0;">{{ __('pages.settings.language_text') }}</p>
                @include('partials.locale-switcher', ['id' => 'settings-locale', 'top' => '0', 'align' => 'flex-end'])
            </div>
        </div>

        <div class="settings-card">
            <div class="settings-caption">{{ __('pages.settings.session') }}</div>
            <div class="plan-row">
                <p class="privacy-text" style="margin:0;">{{ __('pages.settings.signed_in_as', ['email' => auth()->user()?->email]) }}</p>
                <form method="POST" action="{{ route('auth.logout') }}">
                    @csrf
                    <button class="btn-change-plan" type="submit" id="logout-button">{{ __('pages.settings.sign_out') }}</button>
                </form>
            </div>
        </div>

        <div class="settings-card">
            <div class="settings-caption">{{ __('pages.settings.privacy') }}</div>
            {{-- Honest wording only (CLAUDE.md hard constraint #3): photos are
                 deleted within 72 hours — never "immediately after processing". --}}
            <p class="privacy-text">{{ __('pages.settings.privacy_text') }}</p>
        </div>

        <button class="btn-delete-account" id="delete-account" x-on:click="confirmDelete = true">
            {{ __('pages.settings.delete') }}
        </button>
    </div>

    {{-- Confirm-delete dialog (Stage A: UI only, both buttons just close it). --}}
    <div class="confirm-backdrop" x-cloak x-show="confirmDelete" id="confirm-delete">
        <div class="modal-scrim" x-on:click="confirmDelete = false"></div>
        <div class="confirm-card">
            <div class="mark"><svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M10 5.5v5.2" stroke="#A6432E" stroke-width="1.3" stroke-linecap="round"/><circle cx="10" cy="14" r=".9" fill="#A6432E"/></svg></div>
            <h2 style="font-family:'Playfair Display',serif;font-weight:500;font-size:23px;line-height:1.14;color:var(--ink-2);margin:0 0 10px;">{{ __('pages.settings.delete_title') }}</h2>
            <p class="lede" style="font-size:13.5px;margin:0 0 24px;">{{ __('pages.settings.delete_text') }}</p>
            <button class="btn-danger" x-on:click="confirmDelete = false">{{ __('pages.settings.delete_yes') }}</button>
            <button class="btn-keep" x-on:click="confirmDelete = false">{{ __('pages.settings.delete_keep') }}</button>
        </div>
    </div>
@endsection

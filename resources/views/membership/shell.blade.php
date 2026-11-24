@extends('membership.layout')

{{-- Account shell: side navigation from 834px up (210px, 248px from 1280px),
     bottom tabs below 834px — breakpoints per the design's cfg (390/834/1280). --}}

@section('content')
    <div class="acct-shell">
        <aside class="acct-sidebar" id="account-sidebar">
            <a href="{{ route('membership.account.dashboard') }}"><img class="acct-logo" src="{{ asset('Logo_dark.svg') }}" alt="{{ __('common.logo_alt') }}"></a>
            <nav class="acct-nav">
                @foreach ($navItems as $item)
                    <a href="{{ route($item['route'], ['plan' => $plan]) }}"
                       class="{{ $section === $item['key'] ? 'active' : '' }} {{ $item['locked'] ? 'locked' : '' }}">
                        <span class="nav-icon">@include('membership.partials.nav-icon', ['key' => $item['key']])</span>
                        <span>{{ $item['label'] }}</span>
                        @if ($item['locked'])
                            <span class="nav-lock"><svg width="12" height="12" viewBox="0 0 16 16" fill="none"><rect x="3.4" y="7.2" width="9.2" height="6.2" rx="1.5" stroke="#6B6B6B" stroke-width="1.2"/><path d="M5.5 7.2V5.4a2.5 2.5 0 015 0V7.2" stroke="#6B6B6B" stroke-width="1.2"/></svg></span>
                        @endif
                    </a>
                @endforeach
            </nav>
            {{-- Member block as drawn in the Figma frame (1456:10744): avatar,
                 name, plan, then the plan chip and the REAL credit balance. --}}
            <div class="acct-member">
                <a class="acct-user" href="{{ route('membership.account.settings', ['plan' => $plan]) }}">
                    <span class="avatar">@if ($avatarUrl ?? null)<img src="{{ $avatarUrl }}" alt="">@endif</span>
                    <span>
                        <span class="u-name">{{ auth()->user()?->name ?? auth()->user()?->email ?? __('account.member') }}</span>
                        @if ($planData['name'])
                            <span class="u-plan">{{ $planData['name'] }}</span>
                        @endif
                    </span>
                </a>
                @if ($plan)
                    <div class="acct-chips">
                        <span class="acct-chip">{{ $plan }}</span>
                    </div>
                @endif
            </div>
            @include('partials.locale-switcher', ['id' => 'sidebar-locale', 'align' => 'flex-start', 'top' => '16px'])
        </aside>

        {{-- Below 834px the sidebar is gone: a slim bar carries the wordmark
             on one line, the bottom tabs the way home and to the profile. --}}
        <header class="acct-topbar" id="account-topbar">
            <a class="acct-wordmark" href="{{ route('membership.account.dashboard') }}" aria-label="Atelier Fit by Atelier Fit">
                <span class="wm-name">Atelier Fit</span>
                <span class="wm-sep" aria-hidden="true"></span>
                <span class="wm-by">by Atelier Fit</span>
            </a>
        </header>

        <main class="acct-content">
            <div class="acct-topline">
                {{-- Dev-only plan switcher (Stage A: mock state via ?plan=, no DB);
                     local/testing only, real members never see it. --}}
                @if (\App\Services\Membership\AccountShell::planPreview())
                    <div class="plan-chips" id="plan-switcher">
                        <span class="chips-label">{{ __('account.plan') }}</span>
                        @foreach (['fashionista' => 'Fashionista', 'shopaholic' => 'Shopaholic', 'couture' => 'Couture', 'vip' => 'Diva'] as $key => $label)
                            <a href="{{ url()->current().'?plan='.$key }}" class="{{ $plan === $key ? 'active' : '' }}">{{ $label }}</a>
                        @endforeach
                        @yield('extra-chips')
                    </div>
                @endif
                {{-- Credits, notifications, avatar (frame 1456:10458). The bell
                     is inert: no notification feed exists yet. --}}
                <div class="acct-topline-right" id="account-topline">
                    {{-- The live balance: the dashboard's spendCredit() updates #token-balance. --}}
                    <span class="acct-chip">
                        <svg width="8" height="8" viewBox="0 0 8 8" fill="none" aria-hidden="true"><path d="M4 .4l1 2.6 2.6 1-2.6 1-1 2.6-1-2.6L.4 4 3 3z" stroke="currentColor" stroke-width=".8" stroke-linejoin="round"/></svg>
                        <span id="token-balance">{{ $tokensLeft }}</span> {{ trans_choice('common.credits', $tokensLeft) }}
                    </span>
                    <button type="button" class="acct-bell" disabled title="{{ __('account.bell_soon') }}" aria-label="{{ __('account.bell') }}">
                        <svg width="26" height="26" viewBox="0 0 26 26" fill="none" aria-hidden="true"><path d="M7 17.5V11a6 6 0 0112 0v6.5l1.5 2h-15l1.5-2z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M10.5 21a2.5 2.5 0 005 0" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="19.5" cy="6.5" r="2.5" stroke="currentColor" stroke-width="1.3"/></svg>
                    </button>
                    <a class="acct-avatar" href="{{ route('membership.account.settings', ['plan' => $plan]) }}" aria-label="{{ __('account.nav.settings') }}">@if ($avatarUrl ?? null)<img src="{{ $avatarUrl }}" alt="">@endif</a>
                </div>
            </div>

            @yield('section')
        </main>

        {{-- Five tabs, as drawn (Figma 1872:8303). The colour page and the
             stylist are reached from the profile on a phone. --}}
        <nav class="acct-tabs" id="account-tabs">
            @foreach ($navItems as $item)
                @continue (! array_key_exists($item['key'], \App\Services\Membership\AccountShell::TAB_ICONS))
                <a href="{{ route($item['route'], ['plan' => $plan]) }}"
                   class="{{ $section === $item['key'] ? 'active' : '' }}" @if ($section === $item['key']) aria-current="page" @endif>
                    <span class="tab-icon" style="--icon: url('{{ asset('images/lk-mob/'.\App\Services\Membership\AccountShell::TAB_ICONS[$item['key']]) }}')" aria-hidden="true"></span>
                    <span class="tab-label">{{ $item['short'] }}</span>
                </a>
            @endforeach
        </nav>
    </div>
@endsection

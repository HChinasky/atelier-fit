@extends('membership.layout')

@section('title', __('data_deletion.title'))

{{-- /data-deletion — the public "how to delete your data" page (Meta asks
     for one for the WhatsApp app). Every statement here restates sections 7
     and 8 of the Privacy Policy; when those change, this page changes with
     them. Requests are handled by a person, by e-mail: there is no
     self-service deletion yet, so the page must not suggest one. --}}

@section('styles')
        .dd-page { min-height: 100vh; display: flex; justify-content: center;
            padding: 44px 16px 56px; background: radial-gradient(120% 80% at 50% 0%, #FFFFFF, #F0F0F0); }
        @media (min-width: 768px) { .dd-page { padding: 64px 40px; } }
        .dd-card { width: 100%; max-width: 620px; animation: nrise .55s var(--ease) both; }
        @media (min-width: 768px) {
            .dd-card { background: var(--surface); border: 1px solid rgba(45, 45, 45, .08);
                padding: 48px; box-shadow: 0 30px 70px -34px rgba(45, 45, 45, .4); }
        }
        .dd-brand { display: block; height: 34px; width: auto; margin: 0 0 30px; }
        .dd-card .lede { margin: 0; }
        .dd-section { margin-top: 32px; padding-top: 24px; border-top: 1px solid rgba(45, 45, 45, .08); }
        .dd-section h2 { margin: 0 0 12px; font-size: 13px; font-weight: 500; letter-spacing: .12em;
            text-transform: uppercase; color: var(--ink-2); }
        .dd-section p, .dd-section li { font-weight: 300; font-size: 14px; line-height: 1.65; color: var(--ink-2); }
        .dd-section p { margin: 0 0 10px; }
        .dd-section ol, .dd-section ul { margin: 0; padding-inline-start: 20px; }
        .dd-section li + li { margin-top: 6px; }
        .dd-email { display: inline-block; margin: 6px 0 4px; padding: 12px 16px; background: var(--surface-2);
            font-size: 15px; letter-spacing: .02em; color: var(--ink-2); text-decoration: none; overflow-wrap: anywhere; }
        .dd-email:hover { text-decoration: underline; }
        .dd-note { font-size: 12.5px; color: var(--muted-2); }
        .dd-legal { margin-top: 32px; font-weight: 300; font-size: 12px; line-height: 1.6; color: var(--muted-2); }
        .dd-legal a { color: var(--muted-2); text-decoration: underline; }
@endsection

@section('content')
    @php($email = config('legal.privacy_email'))
    <div class="dd-page">
        <main class="dd-card" id="data-deletion">
            <img class="dd-brand" src="{{ asset('Logo_dark.svg') }}" alt="{{ __('common.logo_alt') }}">

            <div class="eyebrow" style="margin-bottom:12px;">{{ __('data_deletion.eyebrow') }}</div>
            <h1 class="page-title" style="margin-bottom:14px;">{{ __('data_deletion.heading') }}</h1>
            <p class="lede">{{ __('data_deletion.intro') }}</p>

            <section class="dd-section" id="dd-how">
                <h2>{{ __('data_deletion.how_heading') }}</h2>
                <ol>
                    <li>
                        {{ __('data_deletion.how_write') }}<br>
                        <a class="dd-email" id="dd-email" href="mailto:{{ $email }}?subject={{ rawurlencode(__('data_deletion.mail_subject')) }}">{{ $email }}</a>
                    </li>
                    <li>{{ __('data_deletion.how_verify') }}</li>
                    <li>{{ __('data_deletion.how_deadline') }}</li>
                </ol>
            </section>

            <section class="dd-section" id="dd-what">
                <h2>{{ __('data_deletion.what_heading') }}</h2>
                <ul>
                    <li>{{ __('data_deletion.what_account') }}</li>
                    <li>{{ __('data_deletion.what_photos') }}</li>
                    <li>{{ __('data_deletion.what_contact') }}</li>
                </ul>
            </section>

            <section class="dd-section" id="dd-auto">
                <h2>{{ __('data_deletion.auto_heading') }}</h2>
                <p>{{ __('data_deletion.auto_photos') }}</p>
                <p class="dd-note">{{ __('data_deletion.auto_provider') }}</p>
            </section>

            <section class="dd-section" id="dd-kept">
                <h2>{{ __('data_deletion.kept_heading') }}</h2>
                <ul>
                    <li>{{ __('data_deletion.kept_backups') }}</li>
                    <li>{{ __('data_deletion.kept_consent') }}</li>
                    <li>{{ __('data_deletion.kept_hash') }}</li>
                </ul>
            </section>

            <p class="dd-legal">
                {!! __('data_deletion.legal', [
                    'privacy' => '<a href="'.e(config('legal.base_url')).'/privacy-policy" target="_blank" rel="noopener">'.e(__('data_deletion.privacy')).'</a>',
                ]) !!}
            </p>

            @include('partials.locale-switcher', ['id' => 'dd-locale'])
        </main>
    </div>
@endsection

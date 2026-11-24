@extends('membership.layout')

@section('title', $title.' — Atelier Fit')

{{-- A legal document rendered from docs/legal/*.md. The text is the
     published English version, identical to the PDF; it is not translated here
     (see CLAUDE.md: legal texts change only with a new version). --}}

@section('styles')
        .ld-page { min-height: 100vh; display: flex; justify-content: center;
            padding: 44px 16px 56px; background: radial-gradient(120% 80% at 50% 0%, #FFFFFF, #F0F0F0); }
        @media (min-width: 768px) { .ld-page { padding: 64px 40px; } }
        .ld-card { width: 100%; max-width: 760px; }
        @media (min-width: 768px) {
            .ld-card { background: var(--surface); border: 1px solid rgba(45, 45, 45, .08); padding: 48px; }
        }
        .ld-brand { display: block; height: 34px; width: auto; margin: 0 0 30px; }
        .ld-doc { font-weight: 300; font-size: 14.5px; line-height: 1.7; color: var(--ink-2); overflow-wrap: anywhere; }
        .ld-doc h1 { font-family: 'Playfair Display', serif; font-weight: 500; font-size: 32px; margin: 0 0 16px; }
        .ld-doc h2 { margin: 34px 0 10px; font-size: 14px; font-weight: 500; letter-spacing: .1em; text-transform: uppercase; }
        .ld-doc h3 { margin: 22px 0 8px; font-size: 15px; font-weight: 500; }
        .ld-doc p { margin: 0 0 12px; }
        .ld-doc ul, .ld-doc ol { margin: 0 0 12px; padding-inline-start: 22px; }
        .ld-doc li + li { margin-top: 4px; }
        .ld-doc a { color: var(--ink-2); text-decoration: underline; }
        .ld-doc hr { border: 0; border-top: 1px solid rgba(45, 45, 45, .08); margin: 24px 0; }
        .ld-doc table { width: 100%; border-collapse: collapse; font-size: 13px; display: block; overflow-x: auto; }
        .ld-doc th, .ld-doc td { border: 1px solid rgba(45, 45, 45, .16); padding: 8px 10px; text-align: start; vertical-align: top; }
        .ld-doc th { background: var(--surface-2); font-weight: 500; }
@endsection

@section('content')
    <div class="ld-page">
        <main class="ld-card">
            <a href="{{ route('login') }}"><img class="ld-brand" src="{{ asset('Logo_dark.svg') }}" alt="{{ __('common.logo_alt') }}"></a>
            <article class="ld-doc">{!! $html !!}</article>
        </main>
    </div>
@endsection

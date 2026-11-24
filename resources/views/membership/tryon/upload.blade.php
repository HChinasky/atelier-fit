@extends('membership.tryon.layout')

@section('title', __('tryon_steps.title_upload'))
{{-- One viewport, no page scroll, as the phone's dashboard (html.m-fit). --}}
@section('html-class', 'ty-fit')

@push('styles')
    <style>
        /* Step 1 · the design hand-over, fitted to the viewport's
           height the way the phone's dashboard is: the header, the step pill,
           the consent and the tabs keep their height; the upload card takes
           what is left (its button sinks to the bottom); when even that is
           not enough the hero gives, down to a shorter picture, and the
           screen scrolls inside only as a last resort. */
        .t1-main { overflow-y: auto; overscroll-behavior: contain; }
        .t1-main > * { flex-shrink: 0; }
        .t1-hero { display: flex; gap: 16px; padding: 18px 20px 0 20px; align-items: stretch; flex: 0 1 auto; min-height: 168px; overflow: hidden; }
        .t1-hero-pic { width: 148px; height: 236px; max-height: 100%; border-radius: 22px; overflow: hidden; flex-shrink: 0; background: transparent; }
        /* The reference's model, a decorative picture cropped from the top. */
        .t1-hero-pic img { width: 100%; height: 100%; object-fit: cover; object-position: center top; display: block; }
        .t1-intro { display: flex; flex-direction: column; justify-content: center; gap: 12px; min-width: 0; flex: 1; min-height: 0; }
        .t1-title { margin: 0; font-family: var(--display); font-weight: 500; font-size: 38px; line-height: 1.02; letter-spacing: -0.02em; }
        .t1-lede { margin: 0; font-size: 13px; line-height: 1.4; color: var(--muted); }
        .t1-tips { list-style: none; margin: 2px 0 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
        .t1-tips li { display: flex; align-items: center; gap: 8px; font-size: 12px; line-height: 1.25; font-weight: 500; }
        /* 30px plus the border, as the reference draws it (content-box): 32 in all. */
        .t1-tip-icon { box-sizing: content-box; width: 30px; height: 30px; border-radius: 15px; background: var(--surface); border: 1px solid var(--border);
            display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

        .t1-step { display: flex; justify-content: center; padding: 22px 20px 0 20px; }
        .t1-pill { margin: 0; display: inline-flex; align-items: center; gap: 10px; background: var(--surface-2); border-radius: var(--pill); padding: 6px 16px 6px 6px; }
        .t1-pill-text { font-family: var(--display); font-weight: 500; font-size: 16px; }

        /* The card is the block that gives: it grows with the viewport and
           never shrinks below its content. */
        .t1-card-wrap { padding: 14px 20px 0 20px; flex: 1 0 auto; display: flex; flex-direction: column; }
        .t1-card { flex: 1 0 auto; background: var(--surface); border: 1.5px dashed var(--dashed); border-radius: 26px; padding: 24px 22px 20px 22px;
            display: flex; flex-direction: column; align-items: center; gap: 12px; text-align: center; position: relative; }
        .t1-card.over { border-color: var(--ink); }
        .t1-icon { width: 68px; height: 68px; border-radius: 34px; background: var(--surface-2); display: flex; align-items: center; justify-content: center; }
        .t1-card-title { margin: 0; font-family: var(--display); font-weight: 500; font-size: 24px; line-height: 1.1; }
        .t1-card-text { margin: 0; font-size: 14px; line-height: 1.45; color: var(--muted); max-width: 260px; }
        .t1-upload { margin-top: auto; width: 100%; height: 54px; flex-shrink: 0; border-radius: 27px; background: var(--ink); color: var(--surface); font-weight: 600; font-size: 16px;
            display: flex; align-items: center; justify-content: center; gap: 10px; cursor: pointer; }
        .t1-upload.is-busy { pointer-events: none; opacity: .7; }
        .t1-file { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
        .t1-upload:has(+ .t1-file:focus-visible) { outline: 2px solid var(--ink); outline-offset: 2px; }
        .t1-formats { margin: 0; font-size: 12px; color: var(--muted); }
        .t1-formats.is-error { color: var(--danger); }
        .t1-consent { display: flex; flex-direction: column; gap: 6px; padding: 12px 20px 0; font-size: 11px; line-height: 1.4; color: var(--muted); }
        .t1-consent label { display: flex; gap: 8px; align-items: flex-start; }

        /* Below 770px the card's icon goes first, so the hero keeps its full
           picture and all three tips. */
        @media (max-height: 770px) {
            .t1-icon { display: none; }
            .t1-card { padding-top: 20px; }
        }
        /* Short phones (375×667), as on the dashboard: no tips, a smaller
           picture, no icon in the card, tighter gaps. */
        @media (max-height: 700px) {
            .t1-hero { padding-top: 12px; min-height: 120px; }
            .t1-hero-pic { width: 120px; height: 180px; }
            .t1-title { font-size: 32px; }
            .t1-tips { display: none; }
            .t1-step { padding-top: 12px; }
            .t1-card-wrap { padding-top: 10px; }
            .t1-card { padding: 16px 18px 14px 18px; gap: 8px; }
            .t1-icon { display: none; }
            .t1-card-title { font-size: 21px; }
            .t1-card-text { font-size: 13px; }
            .t1-upload { height: 48px; border-radius: 24px; }
            .t1-consent { padding-top: 8px; gap: 4px; font-size: 10px; }
        }
    </style>
@endpush

@section('content')
    <main class="t1-main" x-data="tryOnUpload()">
        <section class="t1-hero">
            {{-- The reference's own picture, until the client sends the real one. --}}
            <div class="t1-hero-pic">
                <img id="tryon-hero" src="{{ asset('images/tryon/hero-model.png') }}" alt="{{ __('tryon_steps.hero_alt') }}">
            </div>
            <div class="t1-intro">
                <h1 class="t1-title">{!! __('tryon_steps.heading') !!}</h1>
                <p class="t1-lede">{{ __('tryon_steps.lede') }}</p>
                <ul class="t1-tips">
                    <li>
                        <span class="t1-tip-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2D2D2D" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"></path><circle cx="12" cy="13" r="3.5"></circle></svg></span>
                        <span>{{ __('tryon_steps.tips.full') }}</span>
                    </li>
                    <li>
                        <span class="t1-tip-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2D2D2D" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path></svg></span>
                        <span>{{ __('tryon_steps.tips.light') }}</span>
                    </li>
                    <li>
                        <span class="t1-tip-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2D2D2D" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="5" r="2.5"></circle><path d="M8 10h8M12 10v6M12 16l-3 6M12 16l3 6"></path></svg></span>
                        <span>{{ __('tryon_steps.tips.figure') }}</span>
                    </li>
                </ul>
            </div>
        </section>

        <div class="t1-step">
            <p class="t1-pill"><span class="ty-num">1</span><span class="t1-pill-text">{{ __('tryon_steps.step1') }}</span></p>
        </div>

        <div class="t1-card-wrap">
            <div class="t1-card" id="tryon-drop" :class="{ over: dragOver }"
                 x-on:dragover.prevent="dragOver = true" x-on:dragleave="dragOver = false" x-on:drop.prevent="dropPhoto($event)">
                <div class="t1-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#2D2D2D" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="14" rx="3"></rect><circle cx="9" cy="9" r="1.6"></circle><path d="M3 16l5-4 4 3 3-2 2 1.5"></path><circle cx="18.5" cy="18.5" r="4" fill="#F0F0F0"></circle><path d="M18.5 16.6v3.8M16.6 18.5h3.8"></path></svg>
                </div>
                <h2 class="t1-card-title">{{ __('tryon_steps.add_photo') }}</h2>
                <p class="t1-card-text">{{ __('tryon_steps.add_photo_text') }}</p>
                <label for="photo-upload" class="t1-upload" :class="{ 'is-busy': busy }">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V5M7 10l5-5 5 5M4 20h16"></path></svg>
                    <span x-text="busy ? t.uploading : t.add_photo">{{ __('tryon_steps.add_photo') }}</span>
                </label>
                <input id="photo-upload" class="t1-file" type="file" accept="image/jpeg,image/png,image/webp" :disabled="busy" x-on:change="pickPhoto($event)">
                <p class="t1-formats" id="tryon-photo-error" :class="{ 'is-error': error }" aria-live="polite" x-text="error || t.formats">{{ __('tryon_steps.formats') }}</p>
            </div>
        </div>

        @if (config('consent.required'))
            <div class="t1-consent" id="tryon-consent">
                <label><input type="checkbox" x-model="consent"><span>{{ config('consent.text') }}</span></label>
                <label><input type="checkbox" x-model="terms"><span>{{ config('terms.text') }}
                    <a href="{{ config('legal.base_url') }}/terms-of-use" target="_blank" rel="noopener">Terms</a>.</span></label>
            </div>
        @endif
    </main>
@endsection

@push('scripts')
    <script>
        function tryOnUpload() {
            return {
                t: @js(__('tryon_steps')),
                route: @json($photoRoute),
                consentVersion: @json($consentVersion),
                termsVersion: @json($termsVersion),
                consent: @json(! config('consent.required')),
                terms: @json(! config('consent.required')),
                busy: false,
                error: '',
                dragOver: false,

                pickPhoto(event) {
                    this.upload(event.target.files && event.target.files[0]);
                    event.target.value = '';
                },
                dropPhoto(event) {
                    this.dragOver = false;
                    this.upload(event.dataTransfer && event.dataTransfer.files[0]);
                },
                // A photo that passes goes up at once; step 2 opens when it is stored.
                async upload(file) {
                    if (! file || this.busy) return;
                    if (! ['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
                        this.error = this.t.wrong_type;
                        return;
                    }
                    if (file.size > 12 * 1024 * 1024) {
                        this.error = this.t.too_big;
                        return;
                    }
                    if (! (this.consent && this.terms)) {
                        this.error = this.t.consent;
                        return;
                    }
                    this.error = '';
                    this.busy = true;
                    const body = new FormData();
                    body.append('photo', file);
                    body.append('consent_accepted', '1');
                    body.append('consent_version', this.consentVersion);
                    body.append('terms_accepted', '1');
                    body.append('terms_version', this.termsVersion);
                    try {
                        const response = await fetch(this.route, {
                            method: 'POST',
                            body,
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                        });
                        const data = await response.json().catch(() => ({}));
                        if (response.ok && data.redirect) {
                            window.location.href = data.redirect;
                            return;
                        }
                        const errors = data.errors ? Object.values(data.errors).flat() : [];
                        this.error = errors[0] ?? this.t.upload_failed;
                    } catch (e) {
                        this.error = this.t.no_connection;
                    }
                    this.busy = false;
                },
            };
        }
    </script>
@endpush

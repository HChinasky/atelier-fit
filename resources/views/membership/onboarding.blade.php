@extends('membership.shell')

@section('title', __('onboarding.ui.title'))

{{-- The style quiz: offered once after sign-up, reachable again from the
     profile to change an answer. One question at a time, every one of them
     skippable, the whole quiz skippable too.

     From 1280px it is two columns: the introduction and the list of steps on
     the left, the question on the right, so a wide screen is used rather than
     left two-thirds empty. Below that it is one column, the steps folded into
     the progress bar.

     A question slides in from the side the member is moving towards (back
     slides the other way); the cards share one grid cell, so the outgoing one
     and the incoming one cross without the page jumping. No motion for anyone
     who asked their system for less.

     It is one real <form>: Alpine only decides which question is on screen and
     keeps the answers in hidden inputs, so the submit is a plain POST that
     StoreStyleQuizRequest validates like any other form. --}}

@section('styles')
        .quiz { --slide: 28px; max-width: 720px; }
        @media (min-width: 1280px) {
            /* Centred in the content area: on a wide screen the quiz is the page, not a column of it */
            .quiz { max-width: 1160px; margin-inline: auto; display: grid; grid-template-columns: minmax(0, 340px) minmax(0, 1fr); gap: 72px; align-items: start; }
        }

        /* Left: what this is, and where the member is in it */
        .quiz-intro .lede { margin: 14px 0 0; font-size: 16px; line-height: 1.55; color: var(--ink-2); max-width: 34rem; }
        .quiz-skip-all { margin-top: 18px; padding: 0; border: none; background: none; cursor: pointer; font-family: 'Montserrat', sans-serif;
            font-weight: 300; font-size: 14px; color: var(--muted); text-decoration: underline; text-underline-offset: 3px; }
        .quiz-skip-all:hover { color: var(--ink-2); }
        .quiz-steps { display: none; list-style: none; margin: 36px 0 0; padding: 0; }
        @media (min-width: 1280px) { .quiz-steps { display: block; } }
        .quiz-steps li { display: flex; align-items: center; gap: 14px; padding: 11px 0; border-top: 1px solid var(--line-soft);
            font-size: 15px; color: var(--muted); transition: color .3s ease; }
        .quiz-steps li:last-child { border-bottom: 1px solid var(--line-soft); }
        .quiz-steps li:has(+ li.step-group) { border-bottom: 1px solid var(--line-soft); }
        .quiz-steps li button { all: unset; display: flex; align-items: center; gap: 14px; flex: 1; cursor: pointer; }
        .quiz-steps li button:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 4px; }
        .quiz-steps .num { flex: none; width: 26px; height: 26px; border: 1px solid var(--line-soft); border-radius: 50%;
            display: flex; align-items: center; justify-content: center; font-size: 12px;
            transition: background .3s ease, border-color .3s ease, color .3s ease; }
        .quiz-steps li.step-group { display: block; padding: 20px 0 8px; border-top: none; font-size: 12px; letter-spacing: .08em; color: var(--muted); }
        .quiz-steps li.step-group:first-child { padding-top: 0; }
        .quiz-steps li.step-group + li { border-top-color: var(--ink-2); }
        .quiz-steps li.answered .num { border-color: var(--ink-2); color: var(--ink-2); }
        .quiz-steps li.current { color: var(--ink-2); }
        .quiz-steps li.current .num { background: var(--ink-2); border-color: var(--ink-2); color: var(--surface); }

        /* Right: the question */
        .quiz-main { margin-top: 32px; min-width: 0; }
        @media (min-width: 1280px) { .quiz-main { margin-top: 30px; } }
        .quiz-meta { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; }
        .quiz-count { margin: 0; font-size: 13px; color: var(--muted); }
        .quiz-progress { position: relative; height: 3px; margin-top: 12px; background: rgba(45, 45, 45, .12); overflow: hidden; }
        .quiz-progress span { position: absolute; inset: 0 auto 0 0; background: var(--ink-2); transition: width .45s var(--ease); }

        .quiz-stage { display: grid; margin-top: 20px; overflow: hidden; }
        .quiz-card { grid-area: 1 / 1; padding: 28px 24px; background: var(--surface-2); }
        @media (min-width: 834px) { .quiz-card { padding: 40px 44px; } }
        @media (min-width: 1280px) { .quiz-card { padding: 52px 56px; min-height: 420px; } }
        .quiz-card .topic { margin: 0 0 12px; font-size: 13px; color: var(--muted); }
        .quiz-card h2 { font-size: 26px; line-height: 1.2; }
        @media (min-width: 834px) { .quiz-card h2 { font-size: 32px; } }
        @media (min-width: 1280px) { .quiz-card h2 { font-size: 38px; } }
        .quiz-card .hint { margin: 12px 0 0; font-size: 15px; line-height: 1.5; color: var(--muted); max-width: 36rem; }

        .quiz-options { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; margin: 28px 0 0; }
        @media (min-width: 834px) { .quiz-options { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); } }
        @media (min-width: 1280px) { .quiz-options { margin-top: 36px; } }
        .quiz-options.chips { display: flex; flex-wrap: wrap; }
        .quiz-option { display: flex; align-items: center; gap: 12px; width: 100%; min-height: 56px; padding: 14px 18px; text-align: start;
            border: 1px solid var(--line-soft); border-radius: 0; background: var(--surface); cursor: pointer;
            font-family: 'Montserrat', sans-serif; font-weight: 300; font-size: 15px; line-height: 1.35; color: var(--ink-2);
            transition: border-color .2s ease, background .2s ease, color .2s ease, transform .2s ease; }
        .quiz-options.chips .quiz-option { width: auto; min-width: 72px; justify-content: center; }
        .quiz-option:hover { border-color: var(--ink-2); }
        .quiz-option:active { transform: scale(.98); }
        .quiz-option:focus-visible { outline: 2px solid var(--ink-2); outline-offset: 2px; }
        .quiz-option.active { background: var(--ink-2); border-color: var(--ink-2); color: var(--surface); }
        .quiz-option .box { flex: none; width: 16px; height: 16px; border: 1px solid currentColor; border-radius: 0;
            display: flex; align-items: center; justify-content: center; }

        .quiz-nav { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 24px; }
        .quiz-nav .back { padding: 0; border: none; background: none; cursor: pointer; font-family: 'Montserrat', sans-serif;
            font-weight: 300; font-size: 14px; color: var(--muted); }
        .quiz-nav .back:hover { color: var(--ink-2); }
        .quiz-nav .forward { display: flex; gap: 8px; margin-inline-start: auto; }
        @media (max-width: 560px) {
            .quiz-nav { flex-wrap: wrap; }
            .quiz-nav .forward { width: 100%; margin-inline-start: 0; }
            .quiz-nav .forward .dl-btn { flex: 1; min-width: 0; padding-inline: 12px; }
            .quiz-nav .back { order: 2; width: 100%; padding: 10px 0; text-align: center; }
        }
        .quiz-note { margin: 28px 0 0; font-size: 13px; line-height: 1.5; color: var(--muted); }

        /* The last step: the selfie */
        .selfie { display: grid; grid-template-columns: minmax(0, 1fr); gap: 20px; margin-top: 28px; }
        @media (min-width: 834px) { .selfie { grid-template-columns: minmax(0, 220px) minmax(0, 1fr); gap: 28px; align-items: start; } }
        .selfie-frame { display: block; position: relative; aspect-ratio: 4 / 5; width: 100%; max-width: 260px; margin-inline: auto; overflow: hidden; cursor: pointer;
            background: var(--surface); border: 1px dashed rgba(45, 45, 45, .3); transition: border-color .2s ease; }
        .selfie-frame:hover, .selfie-frame.is-over { border-color: var(--ink-2); }
        .selfie-input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; font-size: 0; }
        .selfie-frame:focus-within { outline: 2px solid var(--ink-2); outline-offset: 2px; }
        .selfie-frame img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: 50% 15%; }
        .selfie-frame .empty { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 10px; padding: 16px; text-align: center; font-size: 13px; line-height: 1.4; color: var(--muted); }
        .selfie-frame .empty .mark { width: 34px; height: 34px; border-radius: 50%; border: 1px solid var(--ink-2);
            display: flex; align-items: center; justify-content: center; }
        .selfie-frame .file { position: absolute; inset: auto 0 0; padding: 8px 10px; font-size: 12px; color: var(--ink-2);
            background: rgba(255, 255, 255, .9); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        @media (min-width: 834px) { .selfie-frame { margin-inline: 0; } }
        .selfie-rules { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
        .selfie-rules li { display: flex; gap: 12px; align-items: flex-start; font-size: 14px; line-height: 1.5; color: var(--muted); }
        .selfie-rules li::before { content: ''; flex: none; width: 6px; height: 6px; border-radius: 50%; margin-top: 8px; background: var(--ink-2); }
        .selfie-consent { display: flex; gap: 12px; align-items: flex-start; margin-top: 18px; padding: 14px 15px; background: var(--surface); cursor: pointer; }
        .selfie-consent input { flex: none; width: 18px; height: 18px; margin: 2px 0 0; accent-color: #2D2D2D; }
        .selfie-consent span { font-size: 13px; line-height: 1.5; color: var(--muted); }
        .selfie-consent a { color: var(--ink-2); }
        .selfie-error { margin: 12px 0 0; font-size: 13px; line-height: 1.5; color: var(--danger); }

        /* The slide: in from the side being moved towards, out the other way */
        .q-enter, .q-leave { transition: opacity .38s var(--ease), transform .38s var(--ease); }
        .q-leave { pointer-events: none; }
        .q-enter-from { opacity: 0; transform: translateX(var(--slide)); }
        .q-leave-to { opacity: 0; transform: translateX(calc(var(--slide) * -1)); }
        .quiz[data-dir="back"] .q-enter-from { transform: translateX(calc(var(--slide) * -1)); }
        .quiz[data-dir="back"] .q-leave-to { transform: translateX(var(--slide)); }
        @media (prefers-reduced-motion: reduce) {
            .q-enter, .q-leave, .quiz-progress span { transition: none; }
        }
@endsection

@section('section')
    @php
        // The selfie is a step, not a question: it closes the quiz while the
        // colour engine is on, and a failed submit reopens on it.
        $keys = array_keys($questions);
        if ($withPhoto) {
            $keys[] = 'photo';
        }
        $steps = count($keys);
        $start = $photoError ? $steps - 1 : (array_search($startKey, $keys, true) ?: 0);
    @endphp
    <div class="section-rise dl quiz" id="onboarding" :data-dir="dir"
         x-data="styleQuiz(@js($keys), @js((object) $answers), {{ $start }})"
         x-on:pageshow.window="restore()">
        <div class="quiz-intro">
            <div class="eyebrow">{{ __('onboarding.ui.eyebrow') }}</div>
            <h1>{{ __('onboarding.ui.heading') }}</h1>
            @if ($withPhoto)
                <p class="lede">{{ __('onboarding.ui.lede_photo') }}</p>
            @else
                <p class="lede">{{ __('onboarding.ui.lede') }}</p>
            @endif

            {{-- The steps are a real sequence, so they are numbered; any one can be jumped to --}}
            <ol class="quiz-steps" aria-label="{{ __('onboarding.ui.steps') }}">
                @foreach ($questions as $key => $question)
                    {{-- A heading wherever the kind of question changes: colours, then shopping --}}
                    @if ($loop->first || $question['group'] !== $previousGroup)
                        <li class="step-group" aria-hidden="true">{{ $question['group'] === 'colour' ? __('onboarding.ui.group_colour') : __('onboarding.ui.group_shopping') }}</li>
                    @endif
                    @php($previousGroup = $question['group'])
                    <li :class="{ current: step === {{ $loop->index }}, answered: step !== {{ $loop->index }} && isAnswered('{{ $key }}') }">
                        <button type="button" x-on:click="go({{ $loop->index }})" :aria-current="step === {{ $loop->index }} ? 'step' : null">
                            <span class="num">{{ $loop->iteration }}</span>
                            <span>{{ $question['short'] }}</span>
                        </button>
                    </li>
                @endforeach
                @if ($withPhoto)
                    <li class="step-group" aria-hidden="true">{{ __('onboarding.ui.selfie') }}</li>
                    <li :class="{ current: step === {{ $steps - 1 }}, answered: step !== {{ $steps - 1 }} && hasPhoto }">
                        <button type="button" x-on:click="go({{ $steps - 1 }})" :aria-current="step === {{ $steps - 1 }} ? 'step' : null">
                            <span class="num">{{ $steps }}</span>
                            <span>{{ __('onboarding.ui.selfie') }}</span>
                        </button>
                    </li>
                @endif
            </ol>

            <form method="POST" action="{{ route('membership.account.onboarding.skip', ['plan' => $plan]) }}">
                @csrf
                <button type="submit" class="quiz-skip-all" id="onboarding-skip">{{ __('onboarding.ui.skip_all') }}</button>
            </form>
        </div>

        <div class="quiz-main">
            <div class="quiz-meta">
                <p class="quiz-count" x-text="stepCount(step + 1, keys.length)" aria-live="polite">{{ __('onboarding.ui.count', ['step' => $start + 1, 'total' => $steps]) }}</p>
            </div>
            <div class="quiz-progress" aria-hidden="true"><span :style="{ width: ((step + 1) / keys.length * 100) + '%' }" style="width: {{ ($start + 1) / $steps * 100 }}%"></span></div>

            <form method="POST" action="{{ route('membership.account.onboarding.store', ['plan' => $plan]) }}" id="onboarding-form" x-ref="form"
                  enctype="multipart/form-data">
                @csrf
                @foreach ($questions as $key => $question)
                    {{-- The answers travel as hidden inputs, whatever question is on screen --}}
                    @if ($question['multiple'])
                        <template x-for="value in (answers.{{ $key }} || [])" :key="value">
                            <input type="hidden" name="{{ $key }}[]" :value="value">
                        </template>
                    @else
                        <input type="hidden" name="{{ $key }}" :value="answers.{{ $key }} ?? ''">
                    @endif
                @endforeach

                <div class="quiz-stage">
                    @foreach ($questions as $key => $question)
                        <section class="quiz-card" id="question-{{ $key }}" x-show="step === {{ $loop->index }}" @if ($loop->index !== $start) x-cloak @endif
                                 x-transition:enter="q-enter" x-transition:enter-start="q-enter-from" x-transition:enter-end=""
                                 x-transition:leave="q-leave" x-transition:leave-start="" x-transition:leave-end="q-leave-to">
                            <p class="topic">{{ $question['group'] === 'colour' ? __('onboarding.ui.group_colour') : __('onboarding.ui.group_shopping') }}</p>
                            <h2>{{ $question['question'] }}</h2>
                            @if ($question['hint'])
                                <p class="hint">{{ $question['hint'] }}</p>
                            @endif

                            <div class="quiz-options {{ $key === 'size' ? 'chips' : '' }}" role="group" aria-label="{{ $question['question'] }}">
                                @foreach ($question['options'] as $value => $label)
                                    @if ($question['multiple'])
                                        <button type="button" class="quiz-option" :class="{ active: has('{{ $key }}', '{{ $value }}') }"
                                                :aria-pressed="has('{{ $key }}', '{{ $value }}')" x-on:click="toggle('{{ $key }}', '{{ $value }}')">
                                            <span class="box" aria-hidden="true"><svg x-show="has('{{ $key }}', '{{ $value }}')" width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M2.5 6.2l2.2 2.2 4.6-4.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                                            {{ $label }}
                                        </button>
                                    @else
                                        <button type="button" class="quiz-option" :class="{ active: answers.{{ $key }} === '{{ $value }}' }"
                                                :aria-pressed="answers.{{ $key }} === '{{ $value }}'" x-on:click="choose('{{ $key }}', '{{ $value }}')">{{ $label }}</button>
                                    @endif
                                @endforeach
                            </div>
                        </section>
                    @endforeach

                    @if ($withPhoto)
                        {{-- The last step: the photo goes with the answers, and the analysis starts on submit --}}
                        <section class="quiz-card" id="question-photo" x-show="step === {{ $steps - 1 }}" @if ($start !== $steps - 1) x-cloak @endif
                                 x-transition:enter="q-enter" x-transition:enter-start="q-enter-from" x-transition:enter-end=""
                                 x-transition:leave="q-leave" x-transition:leave-start="" x-transition:leave-end="q-leave-to">
                            <p class="topic">{{ __('onboarding.ui.group_colour') }}</p>
                            <h2>{{ __('onboarding.ui.selfie_heading') }}</h2>
                            @if ($editing)
                                <p class="hint" id="selfie-editing-hint">{{ __('onboarding.ui.selfie_editing') }}</p>
                            @else
                                <p class="hint">{{ __('onboarding.ui.selfie_hint') }}</p>
                            @endif

                            <div class="selfie">
                                <label class="selfie-frame" :class="{ 'is-over': dragOver }" id="selfie-frame" for="selfie-input"
                                     x-on:dragover.prevent="dragOver = true" x-on:dragleave.prevent="dragOver = false" x-on:drop.prevent="dropPhoto($event)">
                                    <template x-if="photoUrl"><img :src="photoUrl" alt="{{ __('onboarding.ui.your_photo') }}"></template>
                                    <div class="empty" x-show="! photoUrl">
                                        <span class="mark"><svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><circle cx="9" cy="6.2" r="3" stroke="currentColor" stroke-width="1.2"/><path d="M3.5 15c.6-3 2.9-4.6 5.5-4.6S13.9 12 14.5 15" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg></span>
                                        <span x-text="hasPhoto ? @jsText(__('onboarding.ui.photo_chosen')) : @jsText(__('onboarding.ui.drop_photo'))"></span>
                                    </div>
                                    <span class="file" x-show="hasPhoto" x-text="photoName"></span>
                                    <input type="file" name="photo" id="selfie-input" class="selfie-input" accept="image/*,.heic,.heif"
                                           aria-label="{{ __('onboarding.ui.pick_photo') }}" x-ref="photo" x-on:change="pickPhoto($event)">
                                </label>

                                <div>
                                    <ul class="selfie-rules">
                                        @foreach (__('onboarding.ui.rules') as $rule)
                                            <li>{{ $rule }}</li>
                                        @endforeach
                                    </ul>
                                    @if (config('consent.required'))
                                    <label class="selfie-consent" id="selfie-consent">
                                        <input type="checkbox" name="consent" value="1" x-model="consent">
                                        <span>{{ __('onboarding.ui.consent', ['hours' => $photoHours]) }}
                                            <a href="{{ url('legal/privacy-policy') }}" target="_blank" rel="noopener">{{ __('onboarding.ui.privacy') }}</a></span>
                                    </label>
                                    @endif
                                    @if ($photoError)
                                        <p class="selfie-error" id="selfie-error" x-show="! hasPhoto">{{ $photoError }} {{ __('onboarding.ui.photo_again') }}</p>
                                    @endif
                                    <p class="selfie-error" x-show="hasPhoto && ! consent" x-cloak>{{ __('onboarding.ui.consent_needed') }}</p>
                                </div>
                            </div>
                        </section>
                    @endif
                </div>

                <div class="quiz-nav">
                    <button type="button" class="back" x-show="step > 0" x-cloak x-on:click="go(step - 1)">{{ __('onboarding.ui.back') }}</button>
                    <div class="forward">
                        @if ($editing)
                            {{-- Changing one answer should not mean walking every step --}}
                            <button type="button" class="back" id="onboarding-save" x-show="! onPhoto()" :disabled="busy" x-on:click="saveNow()">{{ __('onboarding.ui.save') }}</button>
                        @endif
                        <button type="button" class="dl-btn dl-btn-outline" x-show="! answered() || onPhoto()" :disabled="busy" x-on:click="skip()"
                                x-text="busy ? @jsText(__('onboarding.ui.saving')) : @jsText(__('onboarding.ui.skip'))">{{ __('onboarding.ui.skip') }}</button>
                        <button type="button" class="dl-btn dl-btn-dark" id="onboarding-next" x-show="answered()" x-cloak x-on:click="next()"
                                :disabled="busy || (onPhoto() && ! consent)"
                                x-text="busy ? @jsText(__('onboarding.ui.sending')) : (onPhoto() ? @jsText(__('onboarding.ui.analyse')) : (last() ? @jsText(__('onboarding.ui.done')) : @jsText(__('onboarding.ui.next'))))">{{ __('onboarding.ui.next') }}</button>
                    </div>
                </div>
            </form>

            <p class="quiz-note">{{ __('onboarding.ui.note') }}</p>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        function styleQuiz(keys, answers, startStep) {
            return {
                keys: keys,
                // natural_hair is stored as a bool; the buttons speak '1' / '0'.
                answers: Object.fromEntries(Object.entries(answers).map(([key, value]) =>
                    [key, typeof value === 'boolean' ? (value ? '1' : '0') : value])),
                step: startStep,
                // Which way the cards slide: 'forward' or 'back'.
                dir: 'forward',

                // The selfie step. The file itself lives in the form's own
                // <input type="file">, so the submit is a plain multipart POST.
                hasPhoto: false,
                photoUrl: null,
                photoName: '',
                dragOver: false,
                // No box while consent is switched off (config/consent.php).
                consentRequired: @json((bool) config('consent.required')),
                consent: @json(! config('consent.required')),
                busy: false,

                last() { return this.step === this.keys.length - 1; },

                // "Step 2 of 8", from the same translation the server renders.
                stepCount(step, total) {
                    return @jsText(__('onboarding.ui.count')).replace(':step', step).replace(':total', total);
                },

                onPhoto() { return this.keys[this.step] === 'photo'; },

                // The browser can bring this page back from its cache (Back
                // after the submit, return from the photo picker) exactly as it
                // was left: "sending" still on, buttons dead, and a photo
                // shown that the form no longer holds. Start from what the
                // form actually has.
                restore() {
                    this.busy = false;
                    const file = this.$refs.photo && this.$refs.photo.files && this.$refs.photo.files[0];
                    if (file) {
                        this.usePhoto(file);
                    } else if (this.hasPhoto) {
                        if (this.photoUrl) URL.revokeObjectURL(this.photoUrl);
                        this.hasPhoto = false;
                        this.photoUrl = null;
                        this.photoName = '';
                    }
                },

                pickPhoto(event) { this.usePhoto(event.target.files && event.target.files[0]); },

                dropPhoto(event) {
                    this.dragOver = false;
                    const file = event.dataTransfer && event.dataTransfer.files[0];
                    if (! file) return;
                    // Hand the dropped file to the form's input, so it is what gets sent.
                    const transfer = new DataTransfer();
                    transfer.items.add(file);
                    this.$refs.photo.files = transfer.files;
                    this.usePhoto(file);
                },

                usePhoto(file) {
                    if (! file) return;
                    if (this.photoUrl) URL.revokeObjectURL(this.photoUrl);
                    this.hasPhoto = true;
                    this.photoName = file.name;
                    // A phone's HEIC cannot be drawn by the browser: the frame
                    // then says a photo is chosen and shows its name.
                    this.photoUrl = /image\/(jpeg|png|webp)/.test(file.type) ? URL.createObjectURL(file) : null;
                },

                isAnswered(key) {
                    if (key === 'photo') return this.hasPhoto;
                    const value = this.answers[key];
                    return Array.isArray(value) ? value.length > 0 : value !== undefined && value !== null && value !== '';
                },

                answered() { return this.isAnswered(this.keys[this.step]); },

                has(key, value) { return (this.answers[key] || []).includes(value); },

                toggle(key, value) {
                    const list = this.answers[key] || [];
                    this.answers[key] = list.includes(value) ? list.filter((item) => item !== value) : [...list, value];
                },

                // A single-choice answer moves on by itself — except on the
                // last question, which waits for "Готово". A second click clears it.
                choose(key, value) {
                    if (this.answers[key] === value) {
                        this.answers[key] = '';
                        return;
                    }
                    this.answers[key] = value;
                    if (! this.last()) {
                        const step = this.step;
                        setTimeout(() => { if (this.step === step) this.next(); }, 280);
                    }
                },

                go(index) {
                    if (index < 0 || index >= this.keys.length || index === this.step) return;
                    this.dir = index > this.step ? 'forward' : 'back';
                    // The direction must be on the page before the cards swap,
                    // or the first frame slides the wrong way.
                    this.$nextTick(() => { this.step = index; });
                },

                // Editing: save the answers as they stand, from any step, without a photo.
                saveNow() {
                    if (this.busy) return;
                    if (this.$refs.photo) this.$refs.photo.value = '';
                    this.busy = true;
                    this.$nextTick(() => this.$refs.form.submit());
                },

                // Skipping the selfie drops a photo already chosen: the answers go alone.
                skip() {
                    if (this.onPhoto() && this.hasPhoto) {
                        this.$refs.photo.value = '';
                        this.hasPhoto = false;
                        this.consent = ! this.consentRequired;
                    }
                    this.next();
                },

                next() {
                    if (this.busy) return;
                    if (this.last()) {
                        // A photo goes only with the consent tick; skipping the
                        // step sends the answers alone.
                        if (this.onPhoto() && this.hasPhoto && ! this.consent) return;
                        if (this.onPhoto() && ! this.hasPhoto && this.$refs.photo) this.$refs.photo.value = '';
                        this.busy = true;
                        this.$nextTick(() => this.$refs.form.submit());
                        return;
                    }
                    this.go(this.step + 1);
                },
            };
        }
    </script>
@endsection

@extends('stylist.color.layout')

@section('title', 'Label #' . $profile['id'])

@section('content')
    {{-- The engine's answer is behind a click until a verdict is saved, and the
         reason is the whole value of the dataset: a stylist who sees
         "deep_winter" before deciding will agree with it more often than they
         otherwise would, and the agreement rate is the number being measured.
         A click rather than a hard block, because somebody who has already
         decided has a legitimate reason to look and a block would only teach
         them to open the admin page in another tab. --}}
    <div class="banner">
        <b>Judge the face, not the engine.</b>
        <span>
            What the engine said is behind a click until you save. If you read it first you
            will agree with it more often than you otherwise would &mdash; that is what anchoring
            does to everybody &mdash; and the agreement rate is the one number this whole
            exercise exists to measure. Unsure? Put <code>low</code> and a second season:
            hedging is information, not indecision.
        </span>
    </div>

    <div class="card">
        <h2>The photo</h2>

        @php
            $preview = in_array('source_preview', $profile['views'], true)
                ? 'source_preview'
                : (in_array('source', $profile['views'], true) ? 'source' : null);
        @endphp

        @if ($preview)
            <div class="grid">
                <figure>
                    <img src="{{ route('stylist.color.image', [$profile['id'], $preview]) }}"
                         alt="The photograph being labelled" style="max-height: 420px;">
                    <figcaption>
                        As uploaded, decoded
                        @if ($profile['person_ref'])
                            &middot; {{ $profile['person_ref'] }}
                        @endif
                        @if ($profile['lighting'])
                            &middot; {{ str_replace('_', ' ', $profile['lighting']) }}
                        @endif
                    </figcaption>
                </figure>
            </div>
        @else
            {{-- The photo is on the ordinary purge and nothing here extends its
                 life. Once it is gone the measurements and the label are still
                 a complete row of the dataset, so this is a normal state. --}}
            <p class="small muted">
                The photograph has been deleted &mdash; it is kept for
                {{ (int) config('privacy.photo_hard_delete_hours') }} hours. Label from what you
                know of this person, or skip it: the measurements are still here either way.
            </p>
        @endif
    </div>

    <form method="POST" action="{{ route('stylist.color.store', $profile['id']) }}" class="card">
        @csrf
        <h2>Your verdict</h2>

        <div class="row">
            <div>
                <label for="season">Season <span class="muted">(required)</span></label>
                <select id="season" name="season" required>
                    <option value="">&mdash;</option>
                    @foreach ($options['seasons'] as $season)
                        <option value="{{ $season }}"
                            @selected(old('season', $profile['label']['season'] ?? '') === $season)>
                            {{ __('seasons.names.' . $season) }}
                        </option>
                    @endforeach
                </select>
                @error('season') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="season_alt">Second season <span class="muted">(if it is close)</span></label>
                <select id="season_alt" name="season_alt">
                    <option value="">&mdash;</option>
                    @foreach ($options['seasons'] as $season)
                        <option value="{{ $season }}"
                            @selected(old('season_alt', $profile['label']['season_alt'] ?? '') === $season)>
                            {{ __('seasons.names.' . $season) }}
                        </option>
                    @endforeach
                </select>
                @error('season_alt') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="stylist_confidence">How sure <span class="muted">(required)</span></label>
                <select id="stylist_confidence" name="stylist_confidence" required>
                    <option value="">&mdash;</option>
                    @foreach ($options['confidences'] as $value)
                        <option value="{{ $value }}"
                            @selected(old('stylist_confidence', $profile['label']['stylist_confidence'] ?? '') === $value)>
                            {{ $value }}
                        </option>
                    @endforeach
                </select>
                @error('stylist_confidence') <p class="error">{{ $message }}</p> @enderror
            </div>
        </div>

        <p class="small muted" style="margin: 16px 0 6px;">
            The axes, judged on their own. All optional &mdash; leave one blank rather than guess:
            these are what the engine's reference points eventually get set from, and a guess in
            here becomes a number in there.
        </p>
        <div class="row">
            @foreach (['undertone' => 'undertones', 'depth' => 'depths', 'chroma' => 'chromas'] as $field => $list)
                <div>
                    <label for="{{ $field }}">{{ ucfirst($field) }}</label>
                    <select id="{{ $field }}" name="{{ $field }}">
                        <option value="">not judged</option>
                        @foreach ($options[$list] as $value)
                            <option value="{{ $value }}"
                                @selected(old($field, $profile['label'][$field] ?? '') === $value)>
                                {{ $value }}
                            </option>
                        @endforeach
                    </select>
                    @error($field) <p class="error">{{ $message }}</p> @enderror
                </div>
            @endforeach
        </div>

        <div style="margin-top: 16px;">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes"
                      placeholder="Anything the boxes above cannot hold.">{{ old('notes', $profile['label']['notes'] ?? '') }}</textarea>
            @error('notes') <p class="error">{{ $message }}</p> @enderror
        </div>

        <p style="margin: 18px 0 0;">
            <button type="submit">{{ $profile['label'] ? 'Update' : 'Save' }}</button>
            <a class="button ghost" href="{{ route('stylist.color.index') }}">Back to the queue</a>
        </p>
    </form>

    {{-- Only once there is a verdict on record. Before that it is on the other
         side of a click AND of a save, so reading it cannot change the answer
         that was given. --}}
    @if ($profile['label'])
        <div class="card">
            <h2>What the engine said</h2>
            <p>
                <b>{{ $profile['engine']['season']
                    ? __('seasons.names.' . $profile['engine']['season'])
                    : 'no season' }}</b>
                <span class="muted small">
                    confidence {{ $profile['engine']['confidence'] ?? '—' }}
                </span>
                @if ($profile['engine']['low_confidence'])
                    <span class="pill bad">{{ __('seasons.low_confidence_badge') }}</span>
                @endif
            </p>
            <p class="small muted">
                Yours: <b>{{ __('seasons.names.' . $profile['label']['season']) }}</b>.
                They are kept apart on purpose &mdash; the engine's answer is a measurement, yours
                is a judgement, and comparing them is the point.
            </p>
        </div>
    @else
        {{-- Before a verdict is saved the engine's answer is behind a click,
             not simply absent. A stylist who has already decided and wants to
             see whether the engine agrees has a legitimate reason to look, and
             a hard block would only teach them to open the admin page in
             another tab. What it must not be is the default: read first and you
             will agree more often, which inflates the one number this whole
             exercise measures. --}}
        <details class="card" style="margin-bottom: 20px;">
            <summary style="cursor: pointer; font-weight: 600;">
                Show what the engine said &mdash; only if you have already decided
            </summary>
            <p class="small muted" style="margin-top: 10px;">
                Reading this before you decide will pull your answer towards it. That is what
                anchoring does to everybody, and the agreement rate is what the report measures.
            </p>
            <p>
                <b>{{ $profile['engine']['season']
                    ? __('seasons.names.' . $profile['engine']['season'])
                    : 'no season' }}</b>
                <span class="muted small">
                    confidence {{ $profile['engine']['confidence'] ?? '—' }}
                </span>
                @if ($profile['engine']['low_confidence'])
                    <span class="pill bad">{{ __('seasons.low_confidence_badge') }}</span>
                @endif
            </p>
        </details>
    @endif
@endsection

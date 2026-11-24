{{--
    Internal colour-analysis tool.

    Not a product screen: it is an instrument for checking the engine on real
    faces, reachable only by an administrator and only while the feature flag is
    on. Blade + Alpine, like the rest of the site — there is no SPA here and this
    page is not the place to start one.

    Every colour literal below is from the four-colour brand palette (see
    AppearanceTest, which enforces it). The measured swatches are deliberately
    NOT literals: they come from the server as data, because they are somebody's
    actual skin and hair.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Colour analysis · Atelier Fit</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    {{-- Versioned by mtime: Cloudflare and browsers keep fonts.css for hours, so a font swap otherwise stays invisible. --}}
    <link rel="stylesheet" href="{{ asset('fonts/fonts.css') }}?v={{ @filemtime(public_path('fonts/fonts.css')) }}">
    <style>
        :root {
            --ink: #2D2D2D;
            --base: #FFFFFF;
            --surface: #F0F0F0;
            --muted: #6B6B6B;
            --danger: #A6432E;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 28px 20px 64px;
            font: 15px/1.55 ui-sans-serif, system-ui, "Segoe UI", sans-serif;
            color: var(--ink); background: var(--base);
        }
        .wrap { max-width: 1040px; margin: 0 auto; }
        h1 { font-size: 22px; margin: 0 0 2px; letter-spacing: .01em; }
        h2 { font-size: 15px; margin: 0 0 12px; text-transform: uppercase;
             letter-spacing: .08em; color: var(--muted); font-weight: 600; }
        .lede { color: var(--muted); margin: 0 0 24px; }
        .card { background: var(--base); border: 1px solid rgba(45,45,45,.12);
                border-radius: 0; padding: 20px; margin-bottom: 20px; }
        .banner { background: var(--surface); border: 1px solid rgba(45,45,45,.14);
                  border-radius: 0; padding: 14px 16px; margin-bottom: 20px; }
        .banner b { display: block; margin-bottom: 2px; }
        .banner span { color: var(--muted); font-size: 14px; }
        label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 5px; }
        select, input[type=file] { width: 100%; padding: 9px 10px; font: inherit;
            color: var(--ink); background: var(--base);
            border: 1px solid rgba(45,45,45,.2); border-radius: 0; }
        .row { display: flex; flex-wrap: wrap; gap: 16px; }
        .row > * { flex: 1 1 180px; }
        button { font: inherit; font-weight: 600; padding: 10px 22px; border-radius: 0;
                 border: 1px solid var(--ink); background: var(--ink); color: var(--base);
                 cursor: pointer; }
        button[disabled] { opacity: .45; cursor: default; }
        button.ghost { background: transparent; color: var(--ink); }
        table { border-collapse: collapse; width: 100%; font-variant-numeric: tabular-nums; }
        th, td { text-align: left; padding: 7px 10px; border-bottom: 1px solid rgba(45,45,45,.1); }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
        .swatch { display: inline-block; width: 34px; height: 20px; border-radius: 0;
                  border: 1px solid rgba(45,45,45,.2); vertical-align: middle; }
        /* A zone that was measured and then left out of the calculation. The
           numbers stay — they are the evidence — but they are dimmed, so the
           row cannot be read as one of the ones the aggregate came from. */
        tr.excluded td { color: var(--muted); }
        tr.excluded .swatch { opacity: .45; }
        .zone-note { display: block; font-size: 12px; color: var(--muted); }

        /* The draft banner is not dismissible and does not scroll away with a
           section: nothing below it has been checked by anybody. */
        .banner.draft { border-color: var(--danger); border-width: 2px; }
        .banner.draft b { color: var(--danger); }

        .season { display: flex; flex-wrap: wrap; gap: 10px 28px; align-items: baseline; }
        .season .top { font-size: 20px; font-weight: 600; }
        .season .alt { color: var(--muted); }
        .score { font-variant-numeric: tabular-nums; color: var(--muted); font-size: 13px; }

        /* Axis bars. The marker's position is computed server-side — the browser
           does no arithmetic on a measurement. */
        .axis { margin: 0 0 14px; }
        .axis .ends { display: flex; justify-content: space-between; font-size: 12px;
                      color: var(--muted); margin-bottom: 4px; }
        .axis .track { position: relative; height: 8px; border-radius: 0;
                       background: var(--surface); border: 1px solid rgba(45,45,45,.14); }
        .axis .zero { position: absolute; left: 50%; top: -3px; bottom: -3px; width: 1px;
                      background: rgba(45,45,45,.25); }
        .axis .marker { position: absolute; top: -4px; width: 14px; height: 14px;
                        margin-left: -7px; border-radius: 50%; background: var(--ink);
                        border: 2px solid var(--base); }
        .axis .unknown { font-size: 12px; color: var(--muted); }

        .palette { margin-bottom: 18px; }
        .palette h3 { font-size: 13px; margin: 0 0 2px; font-weight: 600; }
        .palette p { margin: 0 0 8px; }
        .chips { display: flex; flex-wrap: wrap; gap: 8px; }
        .chip { width: 76px; height: 54px; border-radius: 0; cursor: default;
                border: 1px solid rgba(45,45,45,.18); display: flex; align-items: flex-end;
                padding: 5px; font-size: 10px; letter-spacing: .04em; }
        .chip.avoid { border-style: dashed; border-color: var(--danger); }
        .tabs { display: flex; gap: 8px; margin-bottom: 14px; }
        .tabs button { padding: 6px 14px; font-size: 13px; font-weight: 600; }
        .pill { display: inline-block; padding: 2px 10px; border-radius: 0;
                font-size: 12px; font-weight: 600; background: var(--surface); }
        .pill.bad { background: var(--danger); color: var(--base); }
        .muted { color: var(--muted); }
        .small { font-size: 13px; }
        .grid { display: flex; flex-wrap: wrap; gap: 14px; }
        figure { margin: 0; }
        figure img { display: block; max-width: 100%; border-radius: 0;
                     border: 1px solid rgba(45,45,45,.12); background: var(--surface); }
        figcaption { color: var(--muted); font-size: 12px; margin-top: 5px; }
        .meta { display: flex; flex-wrap: wrap; gap: 4px 26px; color: var(--muted);
                margin: 0 0 6px; font-size: 14px; }
        .meta b { color: var(--ink); font-weight: 600; }
        ul.recent { list-style: none; margin: 0; padding: 0; }
        ul.recent li { padding: 8px 0; border-bottom: 1px solid rgba(45,45,45,.1); }
        a { color: var(--ink); }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body>
<div class="wrap"
     x-data="colourAnalysis(@js($selected), @js($recent))"
     x-init="start()">

    <h1>Colour analysis</h1>
    <p class="lede">Internal instrument. Atelier Fit &middot; not linked from the site.
        Access is granted with <code>php artisan user:admin &lt;phone&gt;</code>.</p>

{{-- Stated up front rather than as a footnote. Nothing below it has been
         checked by anybody: the classifier's anchor points were reasoned rather
         than measured against labelled faces, and the palettes were generated
         rather than chosen. The last sentence appears only while the sclera
         target is uncalibrated, because that is a separate, provable fact. --}}
    <div class="banner draft" x-show="showDraftBanner" x-cloak>
        <b x-text="draftBanner"></b>
        <span>
            The season is scored from three axes whose reference points were reasoned from where
            skin, hair and eyes sit in CIELAB &mdash; not fitted to, or tested against, any
            photograph a stylist had labelled. The palettes are generated from those same
            reference points. Both are here to be argued with, not relied on.
        </span>
    </div>

    <div class="card">
        <h2>New analysis</h2>
        <form @submit.prevent="submit">
            <div class="row">
                <div style="flex: 2 1 320px;">
                    <label for="photo">Photo &mdash; the original file, straight from the camera</label>
                    {{-- Each of these has already cost a measurement on a real
                         frame, which is why they are here and not in a manual. --}}
                    <p class="small muted" style="margin: -2px 0 6px;">
                        Daylight, facing a window, no flash and not in direct sun. No headphones,
                        hat or hairband; no glasses; hair back off the forehead and cheeks.
                    </p>
                    <input id="photo" type="file" accept="image/*" x-ref="photo" required>
                </div>
            </div>

            <p class="small muted" style="margin: 14px 0 6px;">
                For a calibration run: which person this is, and what light. Leave blank for a
                one-off. The handle is a label, not a name &mdash; <code>p001</code>.
            </p>
            <div class="row">
                <div>
                    <label for="person_ref">Person handle</label>
                    <input id="person_ref" type="text" x-model="meta.person_ref" placeholder="p001"
                           style="width: 100%; padding: 9px 10px; font: inherit; color: var(--ink);
                                  background: var(--base); border: 1px solid rgba(45,45,45,.2);
                                  border-radius: 0;">
                </div>
                <div>
                    <label for="lighting">Lighting</label>
                    <select id="lighting" x-model="meta.lighting">
                        <option value="">not stated</option>
                        @foreach ($lightings as $value)
                            <option value="{{ $value }}">{{ str_replace('_', ' ', $value) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <p class="small muted" style="margin: 14px 0 6px;">
                Optional. The photo decides; these only break ties once there is something to tie.
            </p>
            <div class="row">
                <div>
                    <label for="natural_hair">Natural hair colour</label>
                    <select id="natural_hair" x-model="quiz.natural_hair">
                        <option value="">not stated</option>
                        <option value="1">yes, natural</option>
                        <option value="0">no, dyed</option>
                    </select>
                </div>
                @foreach ($quizOptions as $field => $values)
                    <div>
                        <label for="{{ $field }}">{{ ucfirst($field) }}</label>
                        <select id="{{ $field }}" x-model="quiz.{{ $field }}">
                            <option value="">not stated</option>
                            @foreach ($values as $value)
                                <option value="{{ $value }}">{{ $value }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>

            <p style="margin: 18px 0 0;">
                <button type="submit" :disabled="busy">
                    <span x-text="busy ? 'Working…' : 'Analyse'"></span>
                </button>
            </p>
            <p class="small" style="color: var(--danger);" x-show="formError" x-cloak x-text="formError"></p>
        </form>
    </div>

    {{-- Result ---------------------------------------------------------- --}}
    <div class="card" x-show="profile" x-cloak>
        <h2>Result</h2>

        <p class="meta">
            <span><b>Status</b>
                <span class="pill" :class="{ bad: profile.status === 'rejected' || profile.status === 'failed' }"
                      x-text="profile.status"></span>
            </span>
            <span x-show="profile.algo_version"><b>Engine</b> <span x-text="profile.algo_version"></span></span>
            <span><b>White balance calibrated</b> <span x-text="profile.target_calibrated ? 'yes' : 'no'"></span></span>
            <span x-show="profile.created_for_humans"><b>Uploaded</b> <span x-text="profile.created_for_humans"></span></span>
        </p>

        <p class="small muted" x-show="profile.pending" x-cloak>
            The engine is working on it. This updates by itself.
        </p>

        <p x-show="profile.reject_message" x-cloak>
            <span x-text="profile.reject_message"></span>
            <span class="muted small" x-text="'(' + profile.reject_reason + ')'"></span>
        </p>

        <p x-show="profile.error" x-cloak style="color: var(--danger);">
            Something on our side broke, so this says nothing about the photo:
            <span x-text="profile.error"></span>
        </p>

        <p x-show="profile.status === 'ok' && !profile.classification" x-cloak class="small muted">
            {{ __('seasons.no_season') }}
        </p>

        {{-- Season ---------------------------------------------------------- --}}
        <template x-if="profile.classification">
            <div>
                <h2 style="margin-top: 22px;">Season</h2>

                <p class="season">
                    <span class="top" x-text="profile.classification.seasons[0].name"></span>
                    <span class="score" x-text="'score ' + fmt(profile.classification.seasons[0].score, 3)"></span>
                    <span class="pill" x-show="profile.classification.draft">{{ __('seasons.draft_badge') }}</span>
                    <span class="pill bad" x-show="profile.classification.low_confidence">
                        {{ __('seasons.low_confidence_badge') }}</span>
                </p>

                <p class="small muted" style="margin: 2px 0 10px;"
                   x-text="profile.classification.seasons[0].description"></p>

                {{-- The runner-up, never hidden. For anybody sitting on a
                     boundary it is a real call, and hiding it would make a thin
                     margin look like a decision. --}}
                <p class="small alt" x-show="profile.classification.seasons.length > 1">
                    Runner-up:
                    <b x-text="profile.classification.seasons[1].name"></b>
                    <span class="score" x-text="'score ' + fmt(profile.classification.seasons[1].score, 3)"></span>
                </p>

                <p class="meta" style="margin-top: 10px;">
                    <span><b>Confidence</b> <span x-text="fmt(profile.classification.confidence, 3)"></span></span>
                    <span x-show="profile.classification.version">
                        <b>Classifier data</b> <span x-text="profile.classification.version"></span></span>
                </p>
            </div>
        </template>

        {{-- Axes ------------------------------------------------------------ --}}
        <template x-if="profile.axes && profile.axes.length">
            <div>
                <h2 style="margin-top: 22px;">Axes</h2>
                <div style="max-width: 460px;">
                    <template x-for="axis in profile.axes" :key="axis.key">
                        <div class="axis">
                            <div class="ends">
                                <span x-text="axis.low"></span>
                                <span style="color: var(--ink); font-weight: 600;"
                                      x-text="axis.label + (axis.value === null ? '' : ' ' + fmt(axis.value))"></span>
                                <span x-text="axis.high"></span>
                            </div>
                            <div class="track">
                                <span class="zero"></span>
                                <template x-if="axis.position !== null">
                                    <span class="marker" :style="{ left: axis.position + '%' }"></span>
                                </template>
                            </div>
                            <p class="unknown" x-show="axis.value === null" style="margin: 4px 0 0;"
                               x-text="axis.missing_text
                                 || 'not measured — every feature behind this axis was excluded'"></p>
                        </div>
                    </template>
                </div>

                {{-- Contrast is not one of the three axes and is not drawn like
                     one. It runs 0..1 in one direction, and it FEEDS the chroma
                     axis rather than standing beside it. --}}
                <template x-if="profile.contrast">
                    <p class="small" style="margin-top: 4px;">
                        <b>{{ __('seasons.axes.contrast') }}</b>
                        <span x-text="fmt(profile.contrast.value)"></span>
                        <span class="muted" x-text="profile.contrast.hint"></span>
                        <span class="muted" x-show="profile.contrast.hair_skin !== null">
                            (&Delta;L* hair&ndash;skin <span x-text="fmt(profile.contrast.hair_skin, 1)"></span>,
                            iris&ndash;sclera <span x-text="fmt(profile.contrast.iris_sclera, 1)"></span>)
                        </span>
                    </p>
                </template>
            </div>
        </template>

        {{-- Why the confidence is what it is -------------------------------- --}}
        <template x-if="profile.classification &&
                        (profile.classification.exclusions.length ||
                         profile.classification.confidence_parts.length)">
            <div>
                <h2 style="margin-top: 22px;">How that confidence was reached</h2>

                <p class="meta">
                    <template x-for="part in profile.classification.confidence_parts" :key="part.key">
                        <span><b x-text="part.label"></b> <span x-text="fmt(part.value, 3)"></span></span>
                    </template>
                </p>

                <ul class="small muted" style="margin: 8px 0 0; padding-left: 18px;"
                    x-show="profile.classification.exclusions.length">
                    <template x-for="item in profile.classification.exclusions"
                              :key="item.axis + '.' + item.feature">
                        <li>
                            <span x-text="item.reason_text"></span>
                            <span class="note" x-text="'(' + item.axis + '.' + item.feature + ')'"></span>
                        </li>
                    </template>
                </ul>
                <p class="small muted" style="margin: 8px 0 0;"
                   x-show="!profile.classification.exclusions.length">
                    Nothing was excluded &mdash; every zone the classifier wanted was measured.
                </p>
            </div>
        </template>

        {{-- Palette --------------------------------------------------------- --}}
        <template x-if="profile.classification">
            <div>
                <h2 style="margin-top: 22px;">Palette</h2>

                {{-- No rows is not an error. The seeder is a command somebody
                     runs, and a page that exploded because nobody had run it
                     would be worse than a page that says so. --}}
                <p class="small muted" x-show="!profile.palettes.length" x-cloak>
                    No palette has been loaded yet. Run
                    <code>php artisan db:seed --class=Database\Seeders\SeasonPaletteSeeder</code>.
                </p>

                <template x-if="profile.palettes.length">
                    <div>
                        <div class="tabs" x-show="profile.palettes.length > 1">
                            <template x-for="entry in profile.palettes" :key="entry.slug">
                                <button type="button"
                                        :class="entry.slug === paletteSlug ? '' : 'ghost'"
                                        @click="paletteSlug = entry.slug"
                                        x-text="entry.name"></button>
                            </template>
                        </div>

                        <template x-for="group in shownPalette.groups" :key="group.name">
                            <div class="palette">
                                <h3 x-text="group.label"></h3>
                                <p class="small muted" x-text="group.hint"></p>
                                <div class="chips">
                                    <template x-for="color in group.colors" :key="color.hex">
                                        <span class="chip"
                                              :class="{ avoid: !group.is_recommended }"
                                              :style="{ background: color.hex, color: color.ink }"
                                              :title="color.title"
                                              x-text="color.hex"></span>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <p class="small muted" x-show="shownPalette.is_draft">
                            Generated from the season prototypes, not chosen by a stylist. Hover a
                            swatch for its L*a*b*.
                        </p>
                    </div>
                </template>
            </div>
        </template>

        {{-- Measured zones. Every zone gets a row, measured or not: a blank
             where a sclera should be tells a reviewer nothing about whether to
             retake the photo, fix a threshold or fix a mask. --}}
        <template x-if="profile.zones && profile.zones.length">
            <div>
                <h2 style="margin-top: 22px;">Measured colour</h2>
                <table>
                    <thead>
                    <tr><th>Zone</th><th></th><th>L*</th><th>a*</th><th>b*</th><th>C*</th><th>h&deg;</th><th>Pixels</th></tr>
                    </thead>
                    <tbody>
                    <template x-for="zone in profile.zones" :key="zone.key">
                        <tr :class="zone.excluded ? 'excluded' : ''">
                            <td>
                                <span x-text="zone.label"></span>
                                {{-- Measured, and then not measured WITH. Without
                                     this the row reads as one of the zones the
                                     aggregate above was computed from. --}}
                                <template x-if="zone.excluded">
                                    <span class="zone-note">excluded from the calculation<span
                                            x-show="zone.reason_text">: <span
                                            x-text="zone.reason_text"></span></span></span>
                                </template>
                                {{-- And on the aggregate itself, how many of the
                                     three sub-zones are actually behind it. --}}
                                <template x-if="zone.pooled_from">
                                    <span class="zone-note"
                                          x-text="'pooled from ' + zone.pooled_from.used
                                              + ' of ' + zone.pooled_from.of + ' sub-zones'"></span>
                                </template>
                            </td>
                            <template x-if="zone.measured">
                                <td><span class="swatch" :style="{ background: zone.swatch }"
                                          :title="zone.swatch"></span></td>
                            </template>
                            <template x-if="zone.measured">
                                <td x-text="fmt(zone.L)"></td>
                            </template>
                            <template x-if="zone.measured">
                                <td x-text="fmt(zone.a)"></td>
                            </template>
                            <template x-if="zone.measured">
                                <td x-text="fmt(zone.b)"></td>
                            </template>
                            <template x-if="zone.measured">
                                <td x-text="fmt(zone.C)"></td>
                            </template>
                            <template x-if="zone.measured">
                                <td x-text="fmt(zone.h, 1)"></td>
                            </template>
                            <template x-if="zone.measured">
                                <td x-text="zone.pixels ?? '—'"></td>
                            </template>
                            <template x-if="!zone.measured">
                                <td colspan="7" class="muted small">
                                    not measured<span x-show="zone.reason_text">
                                        — <span x-text="zone.reason_text"></span></span>
                                    <span class="note" x-show="zone.reason"
                                          x-text="'(' + zone.reason + ')'"></span>
                                </td>
                            </template>
                        </tr>
                    </template>
                    </tbody>
                </table>
                <p class="small muted" style="margin-top: 8px;">
                    The patch is a convenience; the numbers beside it are the measurement.
                </p>
            </div>
        </template>

        {{-- White balance --}}
        <template x-if="profile.white_balance">
            <div>
                <h2 style="margin-top: 22px;">White balance</h2>
                {{-- First, in words: was it even switched on, and if it was,
                     why did nothing happen? "method: none" answers neither. --}}
                <p x-text="profile.white_balance.summary"></p>
                <p class="meta">
                    <span><b>Enabled</b> <span x-text="profile.white_balance.enabled ? 'yes' : 'no'"></span></span>
                    <span><b>Method</b> <span x-text="profile.white_balance.method"></span></span>
                    <span><b>One eye only</b> <span x-text="profile.white_balance.single_eye ? 'yes' : 'no'"></span></span>
                    <span><b>Strength (&Delta;u&prime;v&prime;)</b>
                        <span x-text="fmt(profile.white_balance.correction_strength, 4)"></span></span>
                    <span><b>CCT</b>
                        <span x-text="profile.white_balance.estimated_cct
                            ? fmt(profile.white_balance.estimated_cct, 0) + ' K' : '—'"></span></span>
                    <span><b>Target calibrated</b>
                        <span x-text="profile.white_balance.target_calibrated ? 'yes' : 'no'"></span></span>
                </p>
            </div>
        </template>

        {{-- Images --}}
        <template x-if="profile.images.length || profile.has_source">
            <div>
                <h2 style="margin-top: 22px;">What it looked at</h2>
                <div class="grid">
                    {{-- The decoded preview when there is one: the raw upload
                         may be HEIC, which no browser will draw. --}}
                    <figure x-show="profile.has_preview || profile.has_source">
                        <img :src="imageUrl(profile.has_preview ? 'source_preview' : 'source')"
                             alt="The photo as uploaded" style="max-height: 320px;">
                        <figcaption>
                            As uploaded<span x-show="profile.has_preview"> (decoded: EXIF
                            rotation and ICC applied, exactly as measured)</span>
                        </figcaption>
                    </figure>
                    <template x-for="name in plainViews" :key="name">
                        <figure>
                            <img :src="imageUrl(name)" :alt="caption(name)"
                                 :style="name === 'overlay' || name === 'wb_before_after'
                                    ? 'max-height: 320px' : 'max-height: 200px'">
                            <figcaption x-text="caption(name)"></figcaption>
                        </figure>
                    </template>
                </div>

                {{-- What was actually MEASURED, as opposed to where we looked.
                     A zone can sit perfectly on the overlay and still have had
                     most of itself thrown away by a trim nobody can see. --}}
                <template x-if="sampledViews.length">
                    <div>
                        <h2 style="margin-top: 20px;">Pixels behind each number</h2>
                        <p class="small muted" style="margin: -6px 0 10px;">
                            Full colour is what went into the median. Everything faded was
                            excluded by the masks and the trims.
                        </p>
                        <div class="grid">
                            <template x-for="name in sampledViews" :key="name">
                                <figure>
                                    <img :src="imageUrl(name)" :alt="caption(name)"
                                         style="max-height: 200px">
                                    <figcaption x-text="caption(name)"></figcaption>
                                </figure>
                            </template>
                        </div>
                    </div>
                </template>
                <p class="small muted" style="margin-top: 8px;">
                    Photos and views are deleted within
                    {{ (int) config('privacy.photo_hard_delete_hours') }} hours. The measurements above stay.
                </p>
            </div>
        </template>
    </div>

    {{-- Recent --}}
    <div class="card">
        <h2>Your recent analyses</h2>
        <p class="small muted" x-show="!recent.length" x-cloak>Nothing yet.</p>
        <ul class="recent">
            <template x-for="item in recent" :key="item.id">
                <li>
                    <a href="#" @click.prevent="open(item.id)">
                        <span x-text="'#' + item.id"></span>
                        <span class="pill" :class="{ bad: item.status === 'rejected' || item.status === 'failed' }"
                              x-text="item.status"></span>
                        <span class="muted small" x-text="item.reject_message || ''"></span>
                        <span class="muted small" x-text="item.created_for_humans"></span>
                    </a>
                </li>
            </template>
        </ul>
    </div>
</div>

<script defer src="{{ asset('vendor/alpine.min.js') }}"></script>
<script>
    const COLOUR_ROUTES = {
        store: @js(route('admin.color.store')),
        base: @js(url('/admin/color')),
    };

    const BANNERS = {
        draft: @js(__('seasons.draft_banner')),
        draft_uncalibrated: @js(__('seasons.draft_banner_uncalibrated')),
    };

    const VIEW_CAPTIONS = {
        source_preview: 'As uploaded, decoded',
        overlay: 'Zone masks and polygons',
        crop_left_eye: "Left eye (subject's)",
        crop_right_eye: "Right eye (subject's)",
        crop_left_cheek: "Left cheek (subject's)",
        crop_right_cheek: "Right cheek (subject's)",
        crop_forehead: 'Forehead',
        wb_before_after: 'White balance: before / after',
        sampled_skin: 'Skin — measured pixels',
        sampled_left_cheek: "Left cheek (subject's) — measured pixels",
        sampled_right_cheek: "Right cheek (subject's) — measured pixels",
        sampled_forehead: 'Forehead — measured pixels',
        sampled_hair: 'Hair — measured pixels',
        sampled_iris: 'Iris — measured pixels',
        sampled_sclera: 'Sclera — measured pixels',
    };

    function colourAnalysis(selected, recent) {
        return {
            profile: selected,
            recent: recent,
            quiz: { natural_hair: '', metal: '', tanning: '', veins: '' },
            meta: { person_ref: '', lighting: '' },
            busy: false,
            formError: '',
            timer: null,
            paletteSlug: selected?.palettes?.[0]?.slug ?? null,

            start() {
                // A page opened on a profile that is still queued has to keep
                // looking; one opened on a finished profile must not poll at all.
                this.poll();
            },

            get plainViews() {
                return (this.profile?.images ?? []).filter(
                    (name) => !name.startsWith('sampled_') && name !== 'source_preview'
                );
            },

            get sampledViews() {
                return (this.profile?.images ?? []).filter((name) => name.startsWith('sampled_'));
            },

            get showDraftBanner() {
                // While ANYTHING on the page is a draft. Absence of the flag
                // reads as draft, never as approved.
                const classification = this.profile?.classification;
                const palettes = this.profile?.palettes ?? [];

                return (classification?.draft ?? true) || palettes.some((entry) => entry.is_draft);
            },

            get draftBanner() {
                // The white-balance sentence only while that is actually true:
                // it is a separate, provable fact, not part of the same caveat.
                return (this.profile && this.profile.target_calibrated)
                    ? BANNERS.draft
                    : BANNERS.draft_uncalibrated;
            },

            get shownPalette() {
                const palettes = this.profile?.palettes ?? [];

                return palettes.find((entry) => entry.slug === this.paletteSlug) ?? palettes[0];
            },

            fmt(value, digits = 2) {
                return (value === null || value === undefined) ? '—' : Number(value).toFixed(digits);
            },

            caption(name) {
                return VIEW_CAPTIONS[name] ?? name;
            },

            imageUrl(kind) {
                // Cache-busted per profile, never a bucket URL: every one of
                // these goes through the authorised controller.
                return `${COLOUR_ROUTES.base}/${this.profile.id}/image/${kind}`;
            },

            async submit() {
                const file = this.$refs.photo.files[0];
                if (!file) { this.formError = 'Choose a photo first.'; return; }

                this.busy = true;
                this.formError = '';

                const body = new FormData();
                body.append('photo', file);
                for (const [key, value] of Object.entries({ ...this.quiz, ...this.meta })) {
                    if (value !== '') body.append(key, value);
                }

                try {
                    const response = await fetch(COLOUR_ROUTES.store, {
                        method: 'POST',
                        body,
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.token() },
                    });
                    const payload = await response.json();
                    if (!response.ok) {
                        this.formError = payload.message ?? 'That upload was refused.';
                        return;
                    }
                    this.show(payload);
                    this.recent = [payload, ...this.recent];
                    this.poll();
                } catch (error) {
                    this.formError = 'Could not reach the server.';
                } finally {
                    this.busy = false;
                }
            },

            async open(id) {
                const response = await fetch(`${COLOUR_ROUTES.base}/${id}`, {
                    headers: { 'Accept': 'application/json' },
                });
                if (response.ok) {
                    this.show(await response.json());
                    this.poll();
                }
            },

            show(profile) {
                this.profile = profile;
                // Follow the profile rather than keeping a tab pointing at a
                // season this one was not given.
                this.paletteSlug = profile?.palettes?.[0]?.slug ?? null;
            },

            poll() {
                clearTimeout(this.timer);
                if (!this.profile?.pending) return;

                this.timer = setTimeout(async () => {
                    const response = await fetch(`${COLOUR_ROUTES.base}/${this.profile.id}`, {
                        headers: { 'Accept': 'application/json' },
                    });
                    if (response.ok) {
                        this.show(await response.json());
                        this.recent = this.recent.map(
                            (item) => item.id === this.profile.id ? { ...item, ...this.profile } : item
                        );
                    }
                    this.poll();
                }, 2000);
            },

            token() {
                return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            },
        };
    }
</script>
</body>
</html>

@php
    use App\Models\ColorProfileLabel;
    use App\Services\Color\LabelReport;

    $pct = fn (?float $rate) => $rate === null ? '—' : round($rate * 100, 1) . '%';
    $num = fn ($value) => $value === null ? '—' : $value;
    $short = fn (string $slug) => str_replace('_', ' ', $slug);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Colour labels · engine against stylist</title>
    <style>
        :root { --ink: #2D2D2D; --base: #FFFFFF; --surface: #F0F0F0; --muted: #6B6B6B; --danger: #A6432E; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px 24px 80px; background: var(--base); color: var(--ink);
               font: 15px/1.55 ui-sans-serif, system-ui, "Segoe UI", sans-serif; }
        .wrap { max-width: 1100px; margin: 0 auto; }
        h1 { font-size: 24px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 34px 0 12px; text-transform: uppercase;
             letter-spacing: .08em; color: var(--muted); font-weight: 600; }
        h3 { font-size: 14px; margin: 20px 0 8px; }
        .lede { color: var(--muted); margin: 0 0 6px; }
        .banner { background: var(--surface); border: 1px solid rgba(45,45,45,.14);
                  border-radius: 0; padding: 14px 16px; margin: 18px 0 28px; }
        .banner b { display: block; margin-bottom: 2px; }
        .banner span { color: var(--muted); font-size: 14px; }
        table { border-collapse: collapse; width: 100%; font-variant-numeric: tabular-nums;
                margin-bottom: 8px; }
        th, td { text-align: left; padding: 5px 8px; border-bottom: 1px solid rgba(45,45,45,.1); }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
        td.n, th.n { text-align: right; }
        .muted { color: var(--muted); }
        .small { font-size: 13px; }
        .headline { display: flex; flex-wrap: wrap; gap: 32px; margin-bottom: 12px; }
        .headline div b { display: block; font-size: 26px; font-weight: 600; }
        .headline div span { color: var(--muted); font-size: 13px; }
        .matrix td, .matrix th { padding: 3px 6px; font-size: 12px; text-align: center; }
        .matrix th.row { text-align: left; white-space: nowrap; }
        .matrix td.hit { background: var(--surface); font-weight: 600; }
        .matrix td.zero { color: rgba(45,45,45,.2); }
        .bad { color: var(--danger); font-weight: 600; }
        section { margin-bottom: 8px; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Colour labels: the engine against the stylist</h1>
    <p class="lede small">Generated {{ $report['generated_at'] }}</p>

    <div class="banner">
        <b>Computed, not concluded.</b>
        <span>
            {{ $report['note'] }} Nothing in this file has changed a threshold and nothing in it
            proposes one &mdash; the reason for collecting labels at all was that the reference
            points cannot be set without somebody looking at where the measurements actually fall.
        </span>
    </div>

    @foreach (['all' => 'Everything', 'corrected' => 'White balance applied only'] as $key => $heading)
        @php $set = $report[$key]; @endphp

        <h2>{{ $heading }}</h2>

        @if ($set['labelled'] === 0)
            <p class="small muted">No labels in this subset.</p>
            @continue
        @endif

        {{-- The subset exists because half the point of a calibration set is to
             find out whether the correction helps, and an aggregate over both
             kinds cannot answer that. --}}
        <div class="headline">
            <div><b>{{ $set['labelled'] }}</b><span>labels</span></div>
            <div><b>{{ $set['profiles'] }}</b><span>profiles</span></div>
            <div><b>{{ $pct($set['agreement']['exact']['rate']) }}</b><span>exact agreement</span></div>
            <div><b>{{ $pct($set['agreement']['within_top_2']['rate']) }}</b><span>stylist in engine top&nbsp;2</span></div>
            <div><b>{{ $pct($set['agreement']['same_family']['rate']) }}</b><span>same family</span></div>
        </div>

        <section>
            <h3>How sure the stylists were</h3>
            <table>
                <thead><tr><th>Confidence</th><th class="n">Labels</th></tr></thead>
                <tbody>
                @foreach ($set['by_stylist_confidence'] as $level => $count)
                    <tr><td>{{ $level }}</td><td class="n">{{ $count }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </section>

        <section>
            <h3>Agreement, per axis</h3>
            <p class="small muted" style="margin-top: -4px;">
                Over the labels where the stylist judged that axis at all &mdash; left blank means
                &ldquo;not judged&rdquo;, which is not a disagreement. The engine&rsquo;s side is
                this report&rsquo;s own bucketing of its continuous axes into thirds; there is no
                principled cut yet, which is the thing being calibrated.
            </p>
            <table>
                <thead><tr><th>Axis</th><th class="n">Judged</th><th class="n">Agree</th><th class="n">Rate</th></tr></thead>
                <tbody>
                @foreach ($set['agreement']['axes'] as $axis => $result)
                    <tr>
                        <td>{{ $axis }}</td>
                        <td class="n">{{ $result['judged'] }}</td>
                        <td class="n">{{ $result['hits'] }}</td>
                        <td class="n">{{ $pct($result['rate']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>

        <section>
            <h3>What the engine calls it, when the stylist says&hellip;</h3>
            <p class="small muted" style="margin-top: -4px;">
                Rows are the stylist&rsquo;s verdict, columns the engine&rsquo;s. The diagonal is
                agreement.
            </p>
            <table class="matrix">
                <thead>
                <tr>
                    <th class="row">stylist \ engine</th>
                    @foreach (ColorProfileLabel::SEASONS as $season)
                        <th>{{ implode('', array_map(fn ($w) => strtoupper($w[0]), explode('_', $season))) }}</th>
                    @endforeach
                </tr>
                </thead>
                <tbody>
                @foreach ($set['confusion'] as $stylistSeason => $row)
                    <tr>
                        <th class="row">{{ $short($stylistSeason) }}</th>
                        @foreach (ColorProfileLabel::SEASONS as $engineSeason)
                            @php $count = $row[$engineSeason]; @endphp
                            <td class="{{ $count === 0 ? 'zero' : ($engineSeason === $stylistSeason ? 'hit' : '') }}">
                                {{ $count }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>

        <section>
            <h3>Where the measurements land</h3>
            <p class="small muted" style="margin-top: -4px;">
                Median with the 10th and 90th percentile, grouped by what a stylist called it.
                Percentiles rather than a mean and a standard deviation: these are small samples
                whose outliers are real faces. <b>This is the raw material the reference points get
                set from</b> &mdash; by a person, reading it.
            </p>

            @foreach ($set['distributions'] as $grouping => $groups)
                @if (! count($groups)) @continue @endif
                <h3>By {{ $grouping }}</h3>
                <table>
                    <thead>
                    <tr>
                        <th>{{ $grouping }}</th>
                        <th class="n">n</th>
                        @foreach (LabelReport::FEATURES as $label)
                            <th class="n">{{ $label }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($groups as $value => $group)
                        <tr>
                            <td>{{ $short((string) $value) }}</td>
                            <td class="n">{{ $group['n'] }}</td>
                            @foreach (array_keys(LabelReport::FEATURES) as $feature)
                                @php $spread = $group['features'][$feature]; @endphp
                                <td class="n">
                                    {{ $num($spread['median']) }}
                                    <span class="muted small">
                                        {{ $spread['n'] ? $num($spread['p10']) . '–' . $num($spread['p90']) : '' }}
                                    </span>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endforeach
        </section>

        <section>
            <h3>Worth looking at first</h3>
            <p class="small muted" style="margin-top: -4px;">
                Disagreements, ordered by how wrong and how sure. A family mismatch beats a
                within-family one, and among equals a confident engine beats an unsure one: a wrong
                answer the engine was sure about is where a threshold is actually misplaced, and a
                wrong answer it already flagged as low confidence is the system working.
            </p>
            @if (! count($set['worst']))
                <p class="small muted">No disagreements in this subset.</p>
            @else
                <table>
                    <thead>
                    <tr>
                        <th>Profile</th><th>Person</th><th>Stylist</th><th>Engine</th>
                        <th class="n">Engine conf.</th><th>Stylist conf.</th><th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($set['worst'] as $row)
                        <tr>
                            <td>#{{ $row['profile_id'] }}</td>
                            <td class="small muted">{{ $row['person_ref'] ?? '—' }}</td>
                            <td>{{ $short($row['stylist_season']) }}</td>
                            <td class="{{ $row['family_mismatch'] ? 'bad' : '' }}">
                                {{ $short($row['engine_season']) }}
                                @if ($row['family_mismatch'])
                                    <span class="small">different family</span>
                                @endif
                            </td>
                            <td class="n">{{ $num($row['engine_confidence']) }}</td>
                            <td class="small muted">{{ $row['stylist_confidence'] }}</td>
                            <td class="small">
                                {{-- A relative link: the report is a file, and
                                     whoever opens it knows which host it came
                                     from. Baking one in would make the file
                                     wrong the moment it is copied. --}}
                                <a href="/stylist/color/{{ $row['profile_id'] }}">label</a>
                                &middot;
                                <a href="/admin/color?profile={{ $row['profile_id'] }}">measurements</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endforeach
</div>
</body>
</html>

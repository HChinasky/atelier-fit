@extends('stylist.color.layout')

@section('title', 'Colour labelling')

@section('content')
    @if (session('labelled'))
        <div class="banner">
            <b>Saved.</b>
            <span>Profile #{{ session('labelled') }} is labelled.</span>
        </div>
    @endif

    <div class="card">
        <p class="counts">
            <span>Labelled by you <b>{{ $counts['labelled'] }}</b></span>
            <span>Left to do <b>{{ $counts['remaining'] }}</b></span>
            <span>Measured profiles <b>{{ $counts['total'] }}</b></span>
        </p>

        <form method="GET" action="{{ route('stylist.color.index') }}">
            <div class="row">
                <div>
                    <label for="labelled">Show</label>
                    <select id="labelled" name="labelled" onchange="this.form.submit()">
                        <option value="no" @selected($filters['labelled'] === 'no')>not yet labelled</option>
                        <option value="yes" @selected($filters['labelled'] === 'yes')>labelled by me</option>
                        <option value="any" @selected($filters['labelled'] === 'any')>everything</option>
                    </select>
                </div>
                <div>
                    <label for="agreement">Against the engine</label>
                    <select id="agreement" name="agreement" onchange="this.form.submit()">
                        <option value="any" @selected($filters['agreement'] === 'any')>either way</option>
                        <option value="match" @selected($filters['agreement'] === 'match')>we agree</option>
                        <option value="differ" @selected($filters['agreement'] === 'differ')>we differ</option>
                    </select>
                </div>
                <div>
                    <label for="low_confidence">Engine confidence</label>
                    <select id="low_confidence" name="low_confidence" onchange="this.form.submit()">
                        <option value="any" @selected($filters['low_confidence'] === 'any')>any</option>
                        <option value="yes" @selected($filters['low_confidence'] === 'yes')>low only</option>
                    </select>
                </div>
                <div>
                    <label for="person_ref">Person handle</label>
                    <input id="person_ref" type="text" name="person_ref" placeholder="p001"
                           value="{{ $filters['person_ref'] }}">
                </div>
            </div>
            <p style="margin: 14px 0 0;"><button type="submit">Apply</button></p>
        </form>
    </div>

    <div class="card">
        <h2>Queue</h2>

        @if (! count($profiles))
            <p class="small muted">Nothing here with those filters.</p>
        @else
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>Person</th>
                    <th>Light</th>
                    <th>Your label</th>
                    <th>Engine</th>
                    <th>Uploaded</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($profiles as $row)
                    <tr>
                        <td>{{ $row['id'] }}</td>
                        {{-- Several shots of one person under different lights
                             are what makes white balance measurable, so the
                             handle is worth seeing at a glance. --}}
                        <td>
                            @if ($row['person_ref'])
                                <a href="{{ route('stylist.color.index', ['person_ref' => $row['person_ref'], 'labelled' => 'any']) }}">
                                    {{ $row['person_ref'] }}
                                </a>
                            @else
                                <span class="muted">&mdash;</span>
                            @endif
                        </td>
                        <td class="small muted">
                            {{ $row['lighting'] ? str_replace('_', ' ', $row['lighting']) : '—' }}
                        </td>
                        <td>
                            @if ($row['labelled'])
                                {{ __('seasons.names.' . $row['label_season']) }}
                                <span class="muted small">{{ $row['label_confidence'] }}</span>
                            @else
                                <span class="muted">not yet</span>
                            @endif
                        </td>
                        {{-- Shown here only for profiles this stylist has
                             already judged. On the unlabelled ones it would be
                             the anchoring the labelling screen goes out of its
                             way to prevent. --}}
                        <td>
                            @if ($row['labelled'])
                                {{ $row['engine_season']
                                    ? __('seasons.names.' . $row['engine_season'])
                                    : '—' }}
                                @if ($row['agrees'] === false)
                                    <span class="pill bad">differs</span>
                                @endif
                                @if ($row['low_confidence'])
                                    <span class="pill">low</span>
                                @endif
                            @else
                                <span class="muted small">hidden until you label it</span>
                            @endif
                        </td>
                        <td class="small muted">{{ $row['created_for_humans'] }}</td>
                        <td>
                            <a href="{{ route('stylist.color.show', $row['id']) }}">
                                {{ $row['labelled'] ? 'edit' : 'label' }}
                            </a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection

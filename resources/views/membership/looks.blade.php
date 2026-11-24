@extends('membership.shell')

@section('title', __('pages.looks.title'))

@section('styles')
        /* The look itself, whole: the card takes the image's own proportions
           (no fixed 3:4 box), so it fills the card edge to edge — no bands at
           the sides and nothing cropped. Cards in a row keep their own height. */
        .look-grid { align-items: start; }
        .look-media { background: #FFFFFF; border-bottom: 1px solid rgba(45, 45, 45, .08); }
        .look-media.has-image { aspect-ratio: auto; }
        .look-media img { display: block; width: 100%; height: auto; }
        .look-media.expired { background: #F0F0F0; }
        .look-media .media-label { text-align: center; max-width: 80%; line-height: 1.6; }
        .look-more { font-size: 10.5px; color: #6B6B6B; margin: -8px 0 12px; }
        .look-actions a { display: block; text-align: center; text-decoration: none; }
@endsection

@section('section')
    <div class="section-rise" id="looks-section">
        <div class="eyebrow">{{ __('pages.looks.eyebrow') }}</div>
        <h1 class="page-title" style="margin-bottom:22px;">{{ __('pages.looks.heading') }}</h1>

        @if ($looks->isEmpty())
            <div class="empty-box" id="looks-empty">
                <div class="mark">✦</div>
                <h3>{{ __('pages.looks.empty_title') }}</h3>
                <p>{{ __('pages.looks.empty_text') }}</p>
                <a class="btn-dark" style="display:inline-block;padding:14px 30px;font-size:11px;text-decoration:none;" id="looks-empty-tryon"
                   href="{{ route('membership.account.dashboard') }}">{{ __('pages.looks.new_tryon') }}</a>
            </div>
        @else
            <div class="look-grid" id="looks-grid">
                @foreach ($looks as $look)
                    <div class="look-card" data-look="{{ $look['id'] }}">
                        <div class="look-media {{ $look['image'] ? 'has-image' : 'expired' }}">
                            @if ($look['image'])
                                <img src="{{ $look['image'] }}" alt="{{ $look['name'] }}" loading="lazy">
                            @else
                                {{-- The image went with the 72-hour photo lifecycle. --}}
                                <span class="media-label">{{ __('pages.looks.image_expired') }}</span>
                            @endif
                            @if ($look['date'])
                                <span class="media-date">{{ $look['date'] }}</span>
                            @endif
                        </div>
                        <div class="look-body">
                            <div class="look-brand">{{ $look['brand'] }}</div>
                            <div class="look-name">{{ $look['name'] }}</div>
                            @if ($look['more'] > 0)
                                <div class="look-more">{{ __('pages.looks.more_pieces', ['count' => $look['more']]) }}</div>
                            @endif
                            <div class="look-actions">
                                @if ($look['image'] && $look['download'])
                                    <a class="save" href="{{ $look['download'] }}" title="{{ __('pages.looks.save_title') }}" download>{{ __('pages.looks.save') }}</a>
                                @endif
                                @if ($look['shop'])
                                    <a class="shop" href="{{ $look['shop'] }}" title="{{ __('pages.looks.shop_title') }}" target="_blank" rel="noopener">{{ __('pages.looks.shop') }}</a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection

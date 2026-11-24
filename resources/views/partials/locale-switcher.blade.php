{{-- The language switcher: one short mark per language (config/locales.php),
     the current one in ink, the others muted links to /locale/{code}, which
     keeps the choice and comes back to this page. Self-styled so any layout
     can include it. --}}
@php($current = app()->getLocale())
<nav class="locale-switch" id="{{ $id ?? 'locale-switch' }}" aria-label="{{ __('common.language') }}"
     style="display:flex;justify-content:{{ $align ?? 'center' }};gap:14px;margin-top:{{ $top ?? '18px' }};">
    @foreach (\App\Services\Locale\Locales::all() as $code => $language)
        @if ($code === $current)
            <span lang="{{ $code }}" aria-current="true" title="{{ $language['name'] }}"
                  style="font-size:11px;letter-spacing:.16em;color:#2D2D2D;">{{ $language['short'] }}</span>
        @else
            <a href="{{ route('locale.switch', $code) }}" lang="{{ $code }}" hreflang="{{ $code }}"
               title="{{ __('common.switch_to', ['language' => $language['name']]) }}"
               style="font-size:11px;letter-spacing:.16em;color:#6B6B6B;text-decoration:none;">{{ $language['short'] }}</a>
        @endif
    @endforeach
</nav>

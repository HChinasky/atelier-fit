{{-- Line icons from the design's icons map, keyed by nav section. --}}
@switch($key)
    @case('dashboard')
        <svg width="17" height="17" viewBox="0 0 18 18" fill="none"><rect x="2.5" y="2.5" width="5.5" height="5.5" rx="1.4" stroke="currentColor" stroke-width="1.2"/><rect x="10" y="2.5" width="5.5" height="5.5" rx="1.4" stroke="currentColor" stroke-width="1.2"/><rect x="2.5" y="10" width="5.5" height="5.5" rx="1.4" stroke="currentColor" stroke-width="1.2"/><rect x="10" y="10" width="5.5" height="5.5" rx="1.4" stroke="currentColor" stroke-width="1.2"/></svg>
        @break
    @case('looks')
        <svg width="17" height="17" viewBox="0 0 18 18" fill="none"><rect x="2.5" y="2.5" width="13" height="13" rx="2" stroke="currentColor" stroke-width="1.2"/><path d="M2.5 12l3.5-3 3 2.5 3-4 3 3.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="6.5" cy="6.5" r="1.3" stroke="currentColor" stroke-width="1.2"/></svg>
        @break
    @case('color')
        <svg width="17" height="17" viewBox="0 0 18 18" fill="none"><circle cx="6.6" cy="7.6" r="4.2" stroke="currentColor" stroke-width="1.2"/><circle cx="11.4" cy="7.6" r="4.2" stroke="currentColor" stroke-width="1.2"/><circle cx="9" cy="11.6" r="4.2" stroke="currentColor" stroke-width="1.2"/></svg>
        @break
    @case('wardrobe')
        <svg width="17" height="17" viewBox="0 0 18 18" fill="none"><rect x="3" y="2.5" width="12" height="13" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M9 2.5v13" stroke="currentColor" stroke-width="1.2"/><circle cx="6.6" cy="9" r=".7" fill="currentColor"/><circle cx="11.4" cy="9" r=".7" fill="currentColor"/></svg>
        @break
    @case('capsules')
        <svg width="17" height="17" viewBox="0 0 18 18" fill="none"><path d="M9 2.5l6 3.2v6.6L9 15.5l-6-3.2V5.7L9 2.5z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M3 5.7L9 9m0 0l6-3.3M9 9v6.5" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
        @break
    @case('vip')
        <svg width="17" height="17" viewBox="0 0 18 18" fill="none"><path d="M9 2.2l1.9 3.9 4.3.6-3.1 3 .7 4.3L9 12l-3.8 2 .7-4.3-3.1-3 4.3-.6L9 2.2z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
        @break
    @case('settings')
        <svg width="17" height="17" viewBox="0 0 18 18" fill="none"><circle cx="9" cy="6.2" r="3" stroke="currentColor" stroke-width="1.2"/><path d="M3.5 15c.6-3 2.9-4.6 5.5-4.6S13.9 12 14.5 15" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
        @break
@endswitch

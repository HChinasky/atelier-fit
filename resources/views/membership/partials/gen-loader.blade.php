{{-- The try-on loader: the steps the status endpoint sends for THIS
     generation, ticked off as it goes, plus a photo tip. Drawn twice by the
     dashboard: inside the result panel on desktop, full-screen on a phone. --}}
<div class="gen-loader {{ $class ?? '' }}" id="{{ $id }}" x-show="busy" x-cloak role="status" aria-live="polite">
    <div class="gl-head">
        <h2 class="gl-title">{{ __('dashboard.loader.title') }}</h2>
        <p class="gl-sub">{{ __('dashboard.loader.sub') }}</p>
    </div>
    <div class="gl-ring" aria-hidden="true">
        <svg class="gl-spin" viewBox="0 0 120 120" fill="none">
            <circle cx="60" cy="60" r="56" stroke="currentColor" stroke-opacity=".12" stroke-width="5"/>
            <circle cx="60" cy="60" r="56" stroke="currentColor" stroke-width="5" stroke-linecap="round"
                    stroke-dasharray="230 352" transform="rotate(-90 60 60)"/>
        </svg>
        <svg class="gl-mark" width="34" height="34" viewBox="0 0 22 22" fill="none"><path d="M11 3l1.9 4.6L17.5 9.5l-4.6 1.9L11 16l-1.9-4.6L4.5 9.5l4.6-1.9L11 3z" stroke="currentColor" stroke-width="1.1" stroke-linejoin="round"/><path d="M17 14l.7 1.6 1.6.7-1.6.7L17 18.6l-.7-1.6-1.6-.7 1.6-.7L17 14z" stroke="currentColor" stroke-width=".9" stroke-linejoin="round"/></svg>
    </div>
    <ul class="gl-steps">
        <template x-for="(step, i) in loaderSteps" :key="i">
            <li :class="{ done: i < loaderCurrent, active: i === loaderCurrent }">
                <span class="gl-dot" aria-hidden="true">
                    <svg x-show="i < loaderCurrent" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2.5 6.2l2.3 2.3 4.7-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span x-text="step"></span>
            </li>
        </template>
    </ul>
    <div class="gl-tip">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 17.5h6M10 20.5h4M12 3.5a5.5 5.5 0 0 0-3.3 9.9c.5.4.8 1 .8 1.6v.5h5v-.5c0-.6.3-1.2.8-1.6A5.5 5.5 0 0 0 12 3.5z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 .8v1M4 4l.7.7M20 4l-.7.7M1.5 10.5h1M21.5 10.5h1" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
        <div>
            <p class="gl-tip-title">{{ __('dashboard.loader.tip_title') }}</p>
            <p>{{ __('dashboard.loader.tip') }}</p>
        </div>
    </div>
</div>

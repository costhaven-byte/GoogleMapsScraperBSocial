{{-- Switches the interface language and returns to the current page. --}}
@php($current = app()->getLocale())
<div class="flex items-center gap-1" role="group" aria-label="{{ __('app.nav.language') }}">
    @foreach (config('gscraper.locales') as $code => $locale)
        @continue($code === $current)
        <form method="POST" action="{{ route('locale', $code) }}">
            @csrf
            <button class="btn" lang="{{ $code }}" title="{{ __('app.nav.switch_to', ['language' => $locale['native']]) }}">{{ $locale['native'] }}</button>
        </form>
    @endforeach
</div>

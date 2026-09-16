<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Http\Middleware\SetLocale::isRtl() ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-screen place-items-center p-5" data-i18n="{{ json_encode(__('app.js'), JSON_UNESCAPED_UNICODE) }}">
    <div class="w-full max-w-sm">
        <h1 class="mb-1 text-xl font-bold tracking-tight">{{ config('app.name') }}</h1>
        <p class="muted mb-5">{{ __('app.tagline') }}</p>
        <div class="card p-6">
            @if (session('status'))
                <div class="note note-ok mb-4" role="status">{{ session('status') }}</div>
            @endif
            {{ $slot }}
        </div>
        <div class="mt-4 flex items-center justify-between gap-2">
            <a class="muted text-[13px] hover:underline" href="{{ route('help') }}">{{ __('app.nav.help') }}</a>
            <x-language-switch />
        </div>
    </div>
</body>
</html>

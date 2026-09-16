<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Http\Middleware\SetLocale::isRtl() ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- The bundle reads its strings from here: the CSP forbids inline scripts. --}}
<body class="min-h-screen" data-i18n="{{ json_encode(__('app.js'), JSON_UNESCAPED_UNICODE) }}">
    @php
        $nav = [
            ['dashboard', __('app.nav.dashboard'), 'dashboard'],
            ['runs.index', __('app.nav.runs'), 'runs.*'],
            ['leads.index', __('app.nav.leads'), 'leads.*'],
            ['insights', __('app.nav.insights'), 'insights'],
            ['import.create', __('app.nav.import'), 'import.*'],
        ];
        $navClass = fn (bool $current) => ['rounded-lg px-3 py-1.5 text-[13px]', 'bg-soft font-semibold' => $current, 'muted hover:bg-soft' => ! $current];
    @endphp
    <header class="border-b border-line bg-panel">
        <div class="mx-auto flex max-w-[1400px] flex-wrap items-center gap-x-6 gap-y-2 px-5 py-3">
            <a href="{{ route('dashboard') }}" class="text-[17px] font-bold tracking-tight">{{ config('app.name') }}</a>
            @auth
                <nav class="flex flex-wrap items-center gap-1" aria-label="{{ __('app.nav.main') }}">
                    @foreach ($nav as [$route, $label, $pattern])
                        <a href="{{ route($route) }}" @class($navClass(request()->routeIs($pattern)))>{{ $label }}</a>
                    @endforeach
                    @can('admin')
                        <a href="{{ route('admin.users.index') }}" @class($navClass(request()->routeIs('admin.users.*')))>{{ __('app.nav.users') }}</a>
                        <a href="{{ route('admin.workers.index') }}" @class($navClass(request()->routeIs('admin.workers.*')))>{{ __('app.nav.workers') }}</a>
                    @endcan
                </nav>
            @endauth
            <div class="ms-auto flex items-center gap-2">
                <a href="{{ route('help') }}" @class(['btn', 'bg-soft' => request()->routeIs('help')])>? {{ __('app.nav.help') }}</a>
                <x-language-switch />
                @auth
                    <a href="{{ route('account.edit') }}" class="muted text-[13px] hover:underline">{{ auth()->user()->name }}</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="btn">{{ __('app.nav.sign_out') }}</button>
                    </form>
                @else
                    <a class="btn" href="{{ route('login') }}">{{ __('app.auth.sign_in') }}</a>
                @endauth
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-[1400px] px-5 py-6">
        @if (session('status'))
            <div class="note note-ok mb-5" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any() && ! ($hideErrorSummary ?? false))
            <div class="note note-bad mb-5" role="alert">
                <ul class="detail-list">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>

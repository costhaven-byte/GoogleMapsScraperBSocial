<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Http\Middleware\SetLocale::isRtl() ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ config('app.name') }}</title>
    {{-- Self-contained styles: error pages must render even if the asset build is missing. --}}
    <style>
        :root { --paper:#f6f5f1; --panel:#fff; --ink:#1c1b19; --muted:#6b6860; --line:#e4e1d8; }
        @media (prefers-color-scheme: dark) { :root { --paper:#151514; --panel:#1f1e1c; --ink:#ecebe6; --muted:#9c998f; --line:#34322e; } }
        body { margin:0; min-height:100vh; display:grid; place-items:center; background:var(--paper); color:var(--ink); font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif; padding:20px; box-sizing:border-box; }
        .box { max-width:440px; background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:28px; }
        .code { color:var(--muted); font-size:13px; font-weight:600; letter-spacing:.06em; }
        h1 { margin:6px 0 8px; font-size:20px; }
        p { margin:0 0 18px; color:var(--muted); }
        a { color:var(--ink); font-weight:600; }
    </style>
</head>
<body>
    <div class="box">
        <div class="code">{{ __('app.errors.error') }} @yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a href="{{ url('/') }}">{{ __('app.errors.back_to', ['app' => config('app.name')]) }}</a>
    </div>
</body>
</html>

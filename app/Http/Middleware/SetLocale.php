<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the UI language for this request: the visitor's saved choice (cookie),
 * otherwise the app default. Only languages listed in config('gscraper.locales')
 * are accepted, so the cookie can't be used to point at arbitrary files.
 */
class SetLocale
{
    public const COOKIE = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = self::resolve($request->cookie(self::COOKIE));

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    /** The given locale if it is supported, otherwise the app default. */
    public static function resolve(mixed $locale): string
    {
        $locale = is_string($locale) ? $locale : '';

        return array_key_exists($locale, config('gscraper.locales'))
            ? $locale
            : config('app.locale');
    }

    /** Whether the current language is written right to left. */
    public static function isRtl(?string $locale = null): bool
    {
        return (bool) data_get(config('gscraper.locales'), ($locale ?? app()->getLocale()).'.rtl', false);
    }
}

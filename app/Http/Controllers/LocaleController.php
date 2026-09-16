<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class LocaleController extends Controller
{
    /**
     * Switches the interface language and returns to the page the user was on.
     * The choice is a long-lived cookie, so it survives signing out.
     */
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(array_key_exists($locale, config('gscraper.locales')), 404);

        return back()->withCookie(
            Cookie::make(SetLocale::COOKIE, $locale, 60 * 24 * 365, null, null, $request->secure(), true, false, 'lax')
        );
    }
}

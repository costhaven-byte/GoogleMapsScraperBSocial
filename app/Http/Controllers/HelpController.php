<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class HelpController extends Controller
{
    /** The "How to use" guide: every action in the app, in the current language. */
    public function __invoke(): View
    {
        return view('help');
    }
}

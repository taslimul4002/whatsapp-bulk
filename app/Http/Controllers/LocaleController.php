<?php

namespace App\Http\Controllers;

use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class LocaleController extends Controller
{
    public function update(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, Locales::Supported, true), 404);

        $request->session()->put('locale', $locale);
        App::setLocale($locale);

        return back();
    }
}

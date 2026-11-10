<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CurrencyController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('CurrencySettings');
    }

    public function updatePreference(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', Rule::in(DB::table('currencies')->pluck('code')->all())],
        ]);

        $request->user()->forceFill(['currency_code' => $data['currency']])->save();

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z]{3}$/', Rule::unique('currencies', 'code')],
        ]);

        DB::table('currencies')->insert(['code' => $data['code']]);

        return back()->with('success', 'flash.currency_added');
    }

    public function destroy(string $currency): RedirectResponse
    {
        abort_unless(preg_match('/^[A-Z]{3}$/', $currency) === 1, 404);
        abort_if($currency === 'SAR', 403);
        $deleted = DB::table('currencies')->where('code', $currency)->delete();
        abort_unless($deleted, 404);

        return back()->with('success', 'flash.currency_removed');
    }
}

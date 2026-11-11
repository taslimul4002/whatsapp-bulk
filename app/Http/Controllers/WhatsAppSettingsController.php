<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppSettingsController extends Controller
{
    public function edit(): Response
    {
        $account = WhatsAppAccount::find(1);

        return Inertia::render('WhatsAppSettings', [
            'account' => $account?->only('business_account_id', 'phone_number_id', 'api_version'),
            'webhookUrl' => route('whatsapp.webhook'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $account = WhatsAppAccount::find(1);
        $data = $request->validate([
            'business_account_id' => ['required', 'string', 'regex:/^[0-9]{1,32}$/'],
            'phone_number_id' => ['required', 'string', 'regex:/^[0-9]{1,32}$/'],
            'api_version' => ['required', 'string', 'regex:/^v[0-9]{1,3}\.[0-9]{1,2}$/'],
            'access_token' => [$account ? 'nullable' : 'required', 'string', 'max:4096', 'not_regex:/[\r\n]/'],
            'app_secret' => [$account ? 'nullable' : 'required', 'string', 'max:255'],
            'verify_token' => [$account ? 'nullable' : 'required', 'string', 'max:255'],
        ]);

        $account ??= new WhatsAppAccount;
        $account->id = 1;
        foreach (['business_account_id', 'phone_number_id', 'api_version'] as $field) {
            $account->{$field} = $data[$field];
        }
        foreach (['access_token', 'app_secret', 'verify_token'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $account->{$field} = $data[$field];
            }
        }
        $account->save();

        return back()->with('success', 'flash.whatsapp_saved');
    }

    public function test(): RedirectResponse
    {
        $account = WhatsAppAccount::find(1);
        if (! $account) {
            throw ValidationException::withMessages(['connection' => 'whatsapp.save_first']);
        }

        try {
            $response = Http::withToken($account->access_token)
                ->connectTimeout(5)->timeout(10)
                ->get("https://graph.facebook.com/{$account->api_version}/{$account->phone_number_id}", ['fields' => 'id']);
        } catch (ConnectionException $exception) {
            Log::warning('WhatsApp connection test could not reach Meta.');

            throw ValidationException::withMessages(['connection' => 'whatsapp.unreachable']);
        }

        if (! $response->successful() || $response->json('id') !== $account->phone_number_id) {
            Log::warning('WhatsApp connection test failed.', ['status' => $response->status()]);

            throw ValidationException::withMessages(['connection' => 'whatsapp.connection_failed']);
        }

        return back()->with('success', 'flash.whatsapp_connected');
    }
}

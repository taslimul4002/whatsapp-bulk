<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PayPalClient
{
    private function baseUrl(): string
    {
        return match (config('services.paypal.mode')) {
            'sandbox' => 'https://api-m.sandbox.paypal.com',
            'live' => 'https://api-m.paypal.com',
            default => throw new RuntimeException('Invalid PayPal mode.'),
        };
    }

    private function client(): PendingRequest
    {
        $id = config('services.paypal.client_id');
        $secret = config('services.paypal.client_secret');
        if (! is_string($id) || $id === '' || ! is_string($secret) || $secret === '') {
            throw new RuntimeException('PayPal credentials are not configured.');
        }
        $token = Http::connectTimeout(5)->timeout(20)->asForm()->withBasicAuth($id, $secret)
            ->post($this->baseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials'])->throw()->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('PayPal returned an invalid access token.');
        }

        return Http::baseUrl($this->baseUrl())->withToken($token)->acceptJson()->connectTimeout(5)->timeout(20);
    }

    public function createOrder(object $purchase): array
    {
        return $this->client()->withHeaders(['PayPal-Request-Id' => $purchase->request_id])->post('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $purchase->id,
                'amount' => ['currency_code' => 'USD', 'value' => self::amount($purchase->price_cents)],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'return_url' => route('billing.index'),
                'cancel_url' => route('billing.index'),
                'user_action' => 'PAY_NOW',
            ]]],
        ])->throw()->json();
    }

    public function capture(object $purchase): array
    {
        return $this->client()->withHeaders(['PayPal-Request-Id' => $purchase->capture_request_id, 'Prefer' => 'return=representation'])
            ->withBody('{}', 'application/json')
            ->post('/v2/checkout/orders/'.$purchase->paypal_order_id.'/capture')->throw()->json();
    }

    public function order(string $id): array
    {
        return $this->client()->get('/v2/checkout/orders/'.$id)->throw()->json();
    }

    public function refund(object $purchase): array
    {
        return $this->client()->withHeaders(['PayPal-Request-Id' => $purchase->refund_request_id, 'Prefer' => 'return=representation'])
            ->post('/v2/payments/captures/'.$purchase->paypal_capture_id.'/refund', [
                'amount' => ['currency_code' => 'USD', 'value' => self::amount($purchase->price_cents)],
            ])->throw()->json();
    }

    public function verify(Request $request, array $event): bool
    {
        $webhookId = config('services.paypal.webhook_id');
        if (! is_string($webhookId) || $webhookId === '') {
            throw new RuntimeException('PayPal webhook ID is not configured.');
        }
        $data = ['webhook_id' => $webhookId, 'webhook_event' => $event];
        foreach (['auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time'] as $name) {
            $value = $request->header('PAYPAL-'.str_replace('_', '-', $name));
            if (! is_string($value) || $value === '') {
                return false;
            }
            $data[$name] = $value;
        }

        return $this->client()->post('/v1/notifications/verify-webhook-signature', $data)
            ->throw()->json('verification_status') === 'SUCCESS';
    }

    public static function amount(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}

<?php

namespace App\Http\Controllers;

use App\Support\BillingService;
use App\Support\PayPalClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class PayPalWebhookController extends Controller
{
    public function receive(Request $request, PayPalClient $paypal, BillingService $billing): Response
    {
        $event = $request->json()->all();
        $validator = Validator::make($event, [
            'id' => ['required', 'string', 'max:255'],
            'event_type' => ['required', 'string', 'max:255'],
            'resource' => ['required', 'array'],
        ]);
        abort_if($validator->fails(), 400);
        abort_unless($paypal->verify($request, $event), 403);
        if (DB::table('paypal_webhook_events')->where('id', $event['id'])->exists()) {
            return response('', 200);
        }

        $resource = $event['resource'];
        if ($event['event_type'] === 'PAYMENT.CAPTURE.COMPLETED') {
            $orderId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;
            if (! is_string($orderId) || ! preg_match('/^[A-Za-z0-9-]{1,64}$/', $orderId)) {
                throw new RuntimeException('PayPal capture event has no order ID.');
            }
            $purchase = DB::table('billing_purchases')->where('paypal_order_id', $orderId)->first();
            if (! $purchase) {
                throw new RuntimeException('PayPal capture event has no matching purchase.');
            }
            if ($purchase->paypal_capture_id === null) {
                $order = $paypal->order($orderId);
                if (($order['purchase_units'][0]['payments']['captures'][0]['id'] ?? null) !== ($resource['id'] ?? null)) {
                    throw new RuntimeException('PayPal capture event does not match the order.');
                }
                $billing->recordCapture($purchase->id, $order);
            } elseif ($purchase->paypal_capture_id !== ($resource['id'] ?? null)) {
                throw new RuntimeException('PayPal capture event does not match the purchase.');
            }
        } elseif (in_array($event['event_type'], ['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED'], true)) {
            $captureId = $resource['supplementary_data']['related_ids']['capture_id'] ?? null;
            if (! $captureId && isset($resource['id']) && is_string($resource['id'])
                && DB::table('billing_purchases')->where('paypal_capture_id', $resource['id'])->exists()) {
                $captureId = $resource['id'];
            }
            if (! $captureId) {
                foreach ($resource['links'] ?? [] as $link) {
                    if (($link['rel'] ?? null) === 'up' && preg_match('~/v2/payments/captures/([A-Za-z0-9-]{1,64})$~', $link['href'] ?? '', $matches)) {
                        $captureId = $matches[1];
                        break;
                    }
                }
            }
            $purchase = is_string($captureId) ? DB::table('billing_purchases')->where('paypal_capture_id', $captureId)->first() : null;
            if (! $purchase) {
                throw new RuntimeException('PayPal refund event has no matching capture.');
            }
            $providerId = ($event['event_type'] === 'PAYMENT.CAPTURE.REVERSED' || ($resource['id'] ?? null) === $captureId)
                ? 'event:'.$event['id'] : ($resource['id'] ?? null);
            $amount = $resource['amount']['value'] ?? null;
            if (! is_string($providerId) || strlen($providerId) > 255 || ! is_string($amount)
                || ! preg_match('/^([0-9]{1,6})\.([0-9]{2})$/', $amount, $parts)
                || ($resource['amount']['currency_code'] ?? null) !== 'USD') {
                throw new RuntimeException('Invalid PayPal refund event.');
            }
            $cents = (int) $parts[1] * 100 + (int) $parts[2];
            $billing->recordRefund($purchase->id, $providerId, $cents, true);
        }

        DB::table('paypal_webhook_events')->insertOrIgnore([
            'id' => $event['id'], 'type' => $event['event_type'], 'created_at' => now(),
        ]);

        return response('', 200);
    }
}

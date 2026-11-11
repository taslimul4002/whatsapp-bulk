<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class BillingService
{
    public function recordCapture(int $purchaseId, array $order, ?int $actorId = null): void
    {
        DB::transaction(function () use ($purchaseId, $order, $actorId): void {
            $purchase = DB::table('billing_purchases')->where('id', $purchaseId)->lockForUpdate()->first();
            if (! $purchase) {
                throw new RuntimeException('Purchase not found.');
            }
            $units = $order['purchase_units'] ?? [];
            $captures = $units[0]['payments']['captures'] ?? [];
            $capture = $captures[0] ?? [];
            if (($order['id'] ?? null) !== $purchase->paypal_order_id || ($order['status'] ?? null) !== 'COMPLETED'
                || count($units) !== 1 || count($captures) !== 1 || ($units[0]['reference_id'] ?? null) !== (string) $purchase->id
                || ($capture['status'] ?? null) !== 'COMPLETED' || ($capture['amount']['currency_code'] ?? null) !== 'USD'
                || ($capture['amount']['value'] ?? null) !== PayPalClient::amount($purchase->price_cents)
                || ! is_string($capture['id'] ?? null) || ! preg_match('/^[A-Za-z0-9-]{1,64}$/', $capture['id'])) {
                throw new RuntimeException('PayPal capture does not match the purchase.');
            }
            if ($purchase->paypal_capture_id !== null) {
                if ($purchase->paypal_capture_id !== $capture['id']) {
                    throw new RuntimeException('Conflicting PayPal capture.');
                }

                return;
            }
            if ($purchase->status !== 'pending') {
                throw new RuntimeException('Purchase is not awaiting payment.');
            }
            $now = now();
            DB::table('billing_purchases')->where('id', $purchase->id)->update([
                'status' => 'paid', 'paypal_capture_id' => $capture['id'], 'paid_at' => $now,
                'expires_at' => $purchase->expiry_days === null ? null : $now->copy()->addDays($purchase->expiry_days),
                'updated_at' => $now,
            ]);
            DB::table('billing_transactions')->insert([
                'purchase_id' => $purchase->id, 'actor_id' => $actorId, 'type' => 'capture', 'provider_id' => $capture['id'],
                'amount_cents' => $purchase->price_cents, 'currency' => 'USD', 'created_at' => $now,
            ]);
            DB::table('billing_invoices')->insert([
                'purchase_id' => $purchase->id, 'number' => 'INV-'.str_pad((string) $purchase->id, 10, '0', STR_PAD_LEFT),
                'amount_cents' => $purchase->price_cents, 'currency' => 'USD', 'issued_at' => $now,
            ]);
            DB::table('credit_ledger')->insert([
                'workspace_id' => $purchase->workspace_id, 'purchase_id' => $purchase->id,
                'type' => 'purchase', 'idempotency_key' => 'purchase:'.$purchase->id,
                'credits' => $purchase->credits, 'created_at' => $now,
            ]);
        });
    }

    public function expire(int $purchaseId): void
    {
        DB::transaction(function () use ($purchaseId): void {
            $purchase = DB::table('billing_purchases')->where('id', $purchaseId)->lockForUpdate()->first();
            if (! $purchase || $purchase->status !== 'paid' || ! $purchase->expires_at || now()->lt($purchase->expires_at)) {
                return;
            }
            $remaining = (int) DB::table('credit_ledger')->where('purchase_id', $purchaseId)->sum('credits');
            if ($remaining > 0) {
                DB::table('credit_ledger')->insert([
                    'workspace_id' => $purchase->workspace_id, 'purchase_id' => $purchaseId,
                    'type' => 'expiry', 'idempotency_key' => 'expiry:'.$purchaseId, 'credits' => -$remaining, 'created_at' => now(),
                ]);
            }
        });
    }

    public function recordRefund(int $purchaseId, string $providerId, int $amountCents, bool $review, ?int $actorId = null): void
    {
        DB::transaction(function () use ($purchaseId, $providerId, $amountCents, $review, $actorId): void {
            $purchase = DB::table('billing_purchases')->where('id', $purchaseId)->lockForUpdate()->first();
            if (! $purchase || ! $purchase->paypal_capture_id) {
                throw new RuntimeException('Refund has no matching capture.');
            }
            if (DB::table('billing_transactions')->where('provider_id', $providerId)->exists()) {
                return;
            }
            if ($amountCents < 0 || $amountCents > $purchase->price_cents) {
                throw new RuntimeException('Invalid refund amount.');
            }
            DB::table('billing_transactions')->insert([
                'purchase_id' => $purchaseId, 'actor_id' => $actorId, 'type' => $review ? 'external_refund' : 'refund',
                'provider_id' => $providerId, 'amount_cents' => $amountCents, 'currency' => 'USD', 'created_at' => now(),
            ]);
            $remaining = (int) DB::table('credit_ledger')->where('purchase_id', $purchaseId)->sum('credits');
            if ($remaining > 0 && ! DB::table('credit_ledger')->where('purchase_id', $purchaseId)->where('type', 'refund')->exists()) {
                DB::table('credit_ledger')->insert([
                    'workspace_id' => $purchase->workspace_id, 'purchase_id' => $purchaseId,
                    'type' => 'refund', 'idempotency_key' => 'refund:'.$purchaseId, 'credits' => -$remaining, 'created_at' => now(),
                ]);
            }
            $needsReview = $review || (bool) $purchase->review_required;
            DB::table('billing_purchases')->where('id', $purchaseId)->update([
                'status' => $needsReview ? 'review' : 'refunded', 'review_required' => $needsReview,
                'paypal_refund_id' => $providerId, 'updated_at' => now(),
            ]);
        });
    }
}

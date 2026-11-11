<?php

use App\Models\User;
use App\Models\Workspace;
use App\Support\BillingService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function billingAdmin(): User
{
    $user = User::factory()->create();
    $user->forceFill(['type' => 'admin'])->save();

    return $user;
}

function billingPurchase(array $attributes = []): int
{
    $workspace = Workspace::factory()->create();
    $plan = DB::table('billing_plans')->insertGetId([
        'name' => 'Standard', 'price_cents' => 1000, 'credits' => 100,
        'expiry_days' => 30, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return DB::table('billing_purchases')->insertGetId(array_merge([
        'workspace_id' => $workspace->id, 'plan_id' => $plan, 'plan_name' => 'Standard',
        'price_cents' => 1000, 'credits' => 100, 'expiry_days' => 30, 'status' => 'pending',
        'request_id' => (string) Str::uuid(), 'capture_request_id' => (string) Str::uuid(), 'paypal_order_id' => 'ORDER123',
        'created_at' => now(), 'updated_at' => now(),
    ], $attributes));
}

function billingOrder(int $id, string $value = '10.00'): array
{
    return [
        'id' => 'ORDER123', 'status' => 'COMPLETED', 'purchase_units' => [[
            'reference_id' => (string) $id, 'payments' => ['captures' => [[
                'id' => 'CAPTURE123', 'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'USD', 'value' => $value],
            ]]],
        ]],
    ];
}

function billingHttp(): void
{
    config()->set('services.paypal', ['mode' => 'sandbox', 'client_id' => 'client', 'client_secret' => 'secret', 'webhook_id' => 'hook']);
    Http::preventStrayRequests();
}

function billingWebhookHeaders(): array
{
    return [
        'PAYPAL-AUTH-ALGO' => 'SHA256withRSA', 'PAYPAL-CERT-URL' => 'https://api.paypal.com/cert',
        'PAYPAL-TRANSMISSION-ID' => 'transmission', 'PAYPAL-TRANSMISSION-SIG' => 'signature',
        'PAYPAL-TRANSMISSION-TIME' => '2026-11-11T00:00:00Z',
    ];
}

test('billing management requires an administrator even with settings permission', function () {
    $user = User::factory()->create();
    $user->permissions()->create(['permission' => Permissions::Settings]);
    $this->get('/settings/billing')->assertRedirect('/login');
    $this->actingAs($user)->get('/settings/billing')->assertForbidden();
    $this->post('/settings/billing/plans', [])->assertForbidden();
    $this->actingAs(billingAdmin())->get('/settings/billing')->assertOk();
});

test('administrators manage validated packages', function () {
    $this->actingAs(billingAdmin())->post('/settings/billing/plans', [])->assertSessionHasErrors(['name', 'credits', 'price_cents']);
    $this->post('/settings/billing/plans', ['name' => 'Package', 'credits' => 20, 'price_cents' => 300, 'expiry_days' => null, 'active' => true])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('billing_plans', ['name' => 'Package', 'price_cents' => 300, 'credits' => 20]);
});

test('checkout creates a price snapshot and does not grant credits', function () {
    billingHttp();
    $workspace = Workspace::factory()->create();
    $plan = DB::table('billing_plans')->insertGetId([
        'name' => 'Package', 'price_cents' => 1000, 'credits' => 100, 'expiry_days' => 30,
        'active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
        '*/v2/checkout/orders' => Http::response([
            'id' => 'ORDER123', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123']],
        ]),
    ]);
    $this->actingAs(billingAdmin())->post('/settings/billing/purchases', ['workspace_id' => $workspace->id, 'plan_id' => $plan])->assertRedirect();
    $this->assertDatabaseHas('billing_purchases', ['workspace_id' => $workspace->id, 'status' => 'pending', 'price_cents' => 1000, 'credits' => 100, 'paypal_order_id' => 'ORDER123']);
    $this->assertDatabaseCount('credit_ledger', 0);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request['purchase_units'][0]['amount']['value'] === '10.00');
});

test('a browser return grants no credits and repeated server capture grants them once', function () {
    billingHttp();
    $id = billingPurchase();
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
        '*/v2/checkout/orders/ORDER123/capture' => Http::response(billingOrder($id)),
    ]);
    config()->set('inertia.ssr.enabled', false);
    $this->actingAs(billingAdmin())->get('/settings/billing?token=ORDER123')->assertOk();
    $this->assertDatabaseCount('credit_ledger', 0);
    $this->post("/settings/billing/purchases/{$id}/capture")->assertRedirect();
    $this->post("/settings/billing/purchases/{$id}/capture")->assertRedirect();
    $this->assertDatabaseCount('credit_ledger', 1);
    $this->assertDatabaseCount('billing_transactions', 1);
    $this->assertDatabaseCount('billing_invoices', 1);
    $this->assertDatabaseHas('credit_ledger', ['purchase_id' => $id, 'type' => 'purchase', 'credits' => 100]);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/capture'));
});

test('mismatched capture amounts never credit a purchase', function () {
    billingHttp();
    $id = billingPurchase();
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
        '*/v2/checkout/orders/ORDER123/capture' => Http::response(billingOrder($id, '1.00')),
    ]);
    $this->actingAs(billingAdmin())->post("/settings/billing/purchases/{$id}/capture")->assertServerError();
    $this->assertDatabaseCount('credit_ledger', 0);
    $this->assertDatabaseHas('billing_purchases', ['id' => $id, 'status' => 'pending']);
});

test('verified duplicate capture webhooks credit exactly once and invalid signatures do nothing', function () {
    billingHttp();
    $id = billingPurchase();
    $event = ['id' => 'EVENT123', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
        'id' => 'CAPTURE123', 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER123']],
    ]];
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        '*/v2/checkout/orders/ORDER123' => Http::response(billingOrder($id)),
    ]);
    $this->postJson('/webhooks/paypal', $event, billingWebhookHeaders())->assertOk();
    $this->assertDatabaseHas('paypal_webhook_events', ['id' => 'EVENT123', 'type' => 'PAYMENT.CAPTURE.COMPLETED']);
    $this->assertDatabaseHas('credit_ledger', ['purchase_id' => $id, 'credits' => 100]);
    $this->postJson('/webhooks/paypal', $event, billingWebhookHeaders())->assertOk();
    $this->postJson('/webhooks/paypal', $event)->assertForbidden();
    $this->assertDatabaseCount('credit_ledger', 1);
    $this->assertDatabaseCount('paypal_webhook_events', 1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/verify-webhook-signature'));
});

test('a rejected PayPal webhook signature cannot credit an order', function () {
    billingHttp();
    $id = billingPurchase();
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
    ]);
    $event = ['id' => 'BAD123', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
        'id' => 'CAPTURE123', 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER123']],
    ]];
    $this->postJson('/webhooks/paypal', $event, billingWebhookHeaders())->assertForbidden();
    $this->assertDatabaseCount('credit_ledger', 0);
    $this->assertDatabaseHas('billing_purchases', ['id' => $id, 'status' => 'pending']);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/verify-webhook-signature'));
});

test('expired unused credits can be fully refunded without duplicating ledger entries', function () {
    billingHttp();
    $this->freezeTime();
    $id = billingPurchase();
    app(BillingService::class)->recordCapture($id, billingOrder($id));
    $this->travel(31)->days();
    config()->set('inertia.ssr.enabled', false);
    $this->actingAs(billingAdmin())->get('/settings/billing')->assertInertia(fn ($page) => $page->has('balances', 0));
    $this->artisan('billing:expire-credits')->assertSuccessful();
    $this->artisan('billing:expire-credits')->assertSuccessful();
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
        '*/v2/payments/captures/CAPTURE123/refund' => Http::response([
            'id' => 'REFUND123', 'status' => 'COMPLETED', 'amount' => ['value' => '10.00', 'currency_code' => 'USD'],
        ]),
    ]);
    $this->actingAs(billingAdmin())->post("/settings/billing/purchases/{$id}/refund")->assertRedirect();
    $this->assertDatabaseHas('billing_purchases', ['id' => $id, 'status' => 'refunded']);
    $this->assertDatabaseHas('credit_ledger', ['purchase_id' => $id, 'type' => 'expiry', 'credits' => -100]);
    $this->assertDatabaseCount('credit_ledger', 2);
    $this->assertDatabaseCount('billing_transactions', 2);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refund'));
});

test('a verified capture reversal revokes remaining credits', function () {
    billingHttp();
    $id = billingPurchase();
    app(BillingService::class)->recordCapture($id, billingOrder($id));
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
    ]);
    $event = ['id' => 'REVERSAL123', 'event_type' => 'PAYMENT.CAPTURE.REVERSED', 'resource' => [
        'id' => 'CAPTURE123', 'amount' => ['value' => '10.00', 'currency_code' => 'USD'],
    ]];
    $this->postJson('/webhooks/paypal', $event, billingWebhookHeaders())->assertOk();
    $this->assertDatabaseHas('credit_ledger', ['purchase_id' => $id, 'type' => 'refund', 'credits' => -100]);
    $this->assertDatabaseHas('billing_purchases', ['id' => $id, 'status' => 'review', 'review_required' => true]);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/verify-webhook-signature'));
});

test('verified external partial refund revokes credits and flags purchase for review once', function () {
    billingHttp();
    $id = billingPurchase();
    app(BillingService::class)->recordCapture($id, billingOrder($id));
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
    ]);
    $event = ['id' => 'EVENT456', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => [
        'id' => 'REFUND456', 'amount' => ['value' => '5.00', 'currency_code' => 'USD'],
        'links' => [['rel' => 'up', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE123']],
    ]];
    $this->postJson('/webhooks/paypal', $event, billingWebhookHeaders())->assertOk();
    $this->postJson('/webhooks/paypal', $event, billingWebhookHeaders())->assertOk();
    $event['id'] = 'EVENT789';
    $this->postJson('/webhooks/paypal', $event, billingWebhookHeaders())->assertOk();
    $this->assertDatabaseHas('billing_purchases', ['id' => $id, 'status' => 'review', 'review_required' => true]);
    $this->assertDatabaseHas('credit_ledger', ['purchase_id' => $id, 'type' => 'refund', 'credits' => -100]);
    $this->assertDatabaseCount('billing_transactions', 2);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/verify-webhook-signature'));
});

test('purchases with spent credits cannot be refunded', function () {
    $id = billingPurchase();
    app(BillingService::class)->recordCapture($id, billingOrder($id));
    $row = DB::table('billing_purchases')->where('id', $id)->first();
    DB::table('credit_ledger')->insert([
        'workspace_id' => $row->workspace_id, 'purchase_id' => $id,
        'type' => 'usage', 'credits' => -1, 'created_at' => now(),
    ]);
    $this->actingAs(billingAdmin())->post("/settings/billing/purchases/{$id}/refund")->assertConflict();
    $this->assertDatabaseHas('billing_purchases', ['id' => $id, 'status' => 'paid']);
});

test('a refund with uncertain provider outcome stays pending and cannot expose credits', function () {
    billingHttp();
    $id = billingPurchase();
    app(BillingService::class)->recordCapture($id, billingOrder($id));
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
        '*/v2/payments/captures/CAPTURE123/refund' => Http::failedConnection(),
    ]);
    $this->actingAs(billingAdmin())->post("/settings/billing/purchases/{$id}/refund")->assertServerError();
    $this->assertDatabaseHas('billing_purchases', ['id' => $id, 'status' => 'refund_pending']);
    $this->assertDatabaseCount('billing_transactions', 1);
    config()->set('inertia.ssr.enabled', false);
    $this->get('/settings/billing')->assertInertia(fn ($page) => $page->has('balances', 0));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refund'));
});

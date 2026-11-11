<?php

use App\Jobs\ProcessWhatsAppWebhook;
use App\Jobs\SendBroadcastRecipient;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use App\Models\Workspace;
use App\Support\BillingService;
use App\Support\BroadcastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function broadcastFixture(int $credits = 2): array
{
    $admin = User::factory()->create(['type' => 'admin']);
    $workspace = Workspace::factory()->create();
    $account = new WhatsAppAccount;
    $account->id = 1;
    $account->forceFill(['business_account_id' => '123', 'phone_number_id' => '456', 'api_version' => 'v22.0',
        'access_token' => 'token', 'app_secret' => 'secret', 'verify_token' => 'verify'])->save();
    $template = WhatsAppTemplate::factory()->create(['status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}']]]);
    $plan = DB::table('billing_plans')->insertGetId(['name' => 'Test', 'price_cents' => 100, 'credits' => $credits, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $purchase = DB::table('billing_purchases')->insertGetId([
        'workspace_id' => $workspace->id, 'plan_id' => $plan, 'plan_name' => 'Test', 'price_cents' => 100,
        'credits' => $credits, 'status' => 'paid', 'request_id' => (string) Str::uuid(), 'capture_request_id' => (string) Str::uuid(),
        'paypal_capture_id' => 'capture', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('credit_ledger')->insert(['workspace_id' => $workspace->id, 'purchase_id' => $purchase, 'type' => 'purchase', 'idempotency_key' => 'purchase:'.$purchase, 'credits' => $credits, 'created_at' => now()]);
    $data = ['request_id' => (string) Str::uuid(), 'name' => 'News', 'template_id' => $template->id, 'audience' => 'all',
        'classification_ids' => [], 'mapping' => ['name'], 'scheduled_at' => null];

    return [$admin, $workspace, $purchase, $data];
}

test('broadcasts require permission and workspace membership', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture();
    $user = User::factory()->create();
    $this->get('/broadcasts')->assertRedirect('/login');
    $this->actingAs($user)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertForbidden();
    $user->permissions()->create(['permission' => 'broadcasts.manage']);
    $this->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertNotFound();
    $this->assertDatabaseCount('broadcasts', 0);
});

test('creation reserves one credit per opted-in recipient and is idempotent', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture();
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true, 'name' => 'Alice']);
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => false]);
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $this->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $this->assertDatabaseCount('broadcasts', 1);
    $this->assertDatabaseCount('broadcast_recipients', 1);
    expect(DB::table('credit_ledger')->where('purchase_id', $purchase)->sum('credits'))->toBe(1);
    expect(json_decode(DB::table('broadcast_recipients')->value('components'), true)[0]['parameters'][0]['text'])->toBe('Alice');
});

test('insufficient credits roll back the broadcast', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture(1);
    Contact::factory()->count(2)->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasErrors('audience');
    $this->assertDatabaseCount('broadcasts', 0);
    expect(DB::table('credit_ledger')->where('purchase_id', $purchase)->sum('credits'))->toBe(1);
});

test('duplicate jobs send once and a failed delivery refunds once', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture();
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $recipient = DB::table('broadcast_recipients')->first();
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
    (new SendBroadcastRecipient($recipient->id))->handle(app(BroadcastService::class));
    (new SendBroadcastRecipient($recipient->id))->handle(app(BroadcastService::class));
    Http::assertSentCount(1);
    $this->assertDatabaseHas('broadcast_recipients', ['id' => $recipient->id, 'status' => 'sent', 'message_id' => 'wamid.1']);
    app(BroadcastService::class)->fail($recipient->id, 'Rejected');
    app(BroadcastService::class)->fail($recipient->id, 'Rejected');
    expect(DB::table('credit_ledger')->where('purchase_id', $purchase)->sum('credits'))->toBe(2);
    $this->post("/workspaces/{$workspace->id}/broadcasts/{$recipient->broadcast_id}/recipients/{$recipient->id}/retry")->assertSessionHasNoErrors();
    $this->post("/workspaces/{$workspace->id}/broadcasts/{$recipient->broadcast_id}/recipients/{$recipient->id}/retry")->assertSessionHasErrors('retry');
    expect(DB::table('credit_ledger')->where('purchase_id', $purchase)->sum('credits'))->toBe(1);
});

test('uncertain send outcomes are held without retrying or refunding', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture();
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $recipient = DB::table('broadcast_recipients')->first();
    Http::fake(['*' => Http::failedConnection()]);
    (new SendBroadcastRecipient($recipient->id))->handle(app(BroadcastService::class));
    (new SendBroadcastRecipient($recipient->id))->handle(app(BroadcastService::class));
    Http::assertSentCount(1);
    $this->assertDatabaseHas('broadcast_recipients', ['id' => $recipient->id, 'status' => 'review']);
    expect(DB::table('credit_ledger')->where('purchase_id', $purchase)->sum('credits'))->toBe(1);
    $this->post("/workspaces/{$workspace->id}/broadcasts/{$recipient->broadcast_id}/recipients/{$recipient->id}/retry")->assertSessionHasErrors('retry');
});

test('the account limit prevents more than sixty attempts in a rolling minute', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture();
    Contact::factory()->count(2)->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $recipients = DB::table('broadcast_recipients')->orderBy('id')->get();
    for ($i = 0; $i < 59; $i++) {
        DB::table('broadcast_send_attempts')->insert(['phone_number_id' => '456', 'created_at' => now()]);
    }
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.limit']]])]);
    (new SendBroadcastRecipient($recipients[0]->id))->handle(app(BroadcastService::class));
    (new SendBroadcastRecipient($recipients[1]->id))->handle(app(BroadcastService::class));
    Http::assertSentCount(1);
    $this->assertDatabaseHas('broadcast_recipients', ['id' => $recipients[0]->id, 'status' => 'sent']);
    $this->assertDatabaseHas('broadcast_recipients', ['id' => $recipients[1]->id, 'status' => 'pending']);
});

test('reserved credits remain reserved across expiry and cannot be refunded as a purchase', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture();
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $this->post("/settings/billing/purchases/{$purchase}/refund")->assertStatus(409);
    DB::table('billing_purchases')->where('id', $purchase)->update(['expires_at' => now()->subSecond()]);
    app(BillingService::class)->expire($purchase);
    $recipient = DB::table('broadcast_recipients')->first();
    app(BroadcastService::class)->fail($recipient->id, 'Failed');
    expect(DB::table('credit_ledger')->where('purchase_id', $purchase)->sum('credits'))->toBe(0);
    $this->assertDatabaseHas('broadcast_credit_grants', ['source_recipient_id' => $recipient->id, 'balance' => 1]);
    $this->get('/settings/billing')->assertInertia(fn ($page) => $page->where('balances.0.credits', 1));
    $this->post("/workspaces/{$workspace->id}/broadcasts/{$recipient->broadcast_id}/recipients/{$recipient->id}/retry")->assertSessionHasNoErrors();
    $this->assertDatabaseHas('broadcast_credit_grants', ['source_recipient_id' => $recipient->id, 'balance' => 0]);
    app(BroadcastService::class)->fail($recipient->id, 'Failed again');
    $this->assertDatabaseHas('broadcast_credit_grants', ['source_recipient_id' => $recipient->id, 'balance' => 1]);
});

test('scheduled recipients are not sent early and opted out recipients are refunded', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture();
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $data['scheduled_at'] = now()->addHour()->toIso8601String();
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $recipient = DB::table('broadcast_recipients')->first();
    Http::fake();
    (new SendBroadcastRecipient($recipient->id))->handle(app(BroadcastService::class));
    Http::assertNothingSent();
    $contact->update(['opted_in' => false]);
    $this->travel(61)->minutes();
    (new SendBroadcastRecipient($recipient->id))->handle(app(BroadcastService::class));
    $this->assertDatabaseHas('broadcast_recipients', ['id' => $recipient->id, 'status' => 'failed']);
    expect(DB::table('credit_ledger')->where('purchase_id', $purchase)->sum('credits'))->toBe(2);
});

test('failed webhook delivery is refunded and later delivery reverses the refund only once', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture();
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $recipient = DB::table('broadcast_recipients')->first();
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.2']]])]);
    (new SendBroadcastRecipient($recipient->id))->handle(app(BroadcastService::class));
    $event = ['business_account_id' => '123', 'phone_number_id' => '456', 'type' => 'status', 'message_id' => 'wamid.2',
        'status' => 'failed', 'event_timestamp' => '100', 'payload' => '{}', 'context' => '{}', 'created_at' => now(), 'updated_at' => now()];
    DB::table('whatsapp_events')->insert(['event_key' => str_repeat('a', 64), ...$event]);
    $service = app(BroadcastService::class);
    $service->reconcile('wamid.2', '456');
    expect(DB::table('credit_ledger')->where('purchase_id', $purchase)->sum('credits'))->toBe(2);
    DB::table('whatsapp_events')->insert(['event_key' => str_repeat('b', 64), ...$event, 'status' => 'delivered']);
    $service->reconcile('wamid.2', '456');
    $service->reconcile('wamid.2', '456');
    $this->assertDatabaseHas('broadcast_recipients', ['id' => $recipient->id, 'status' => 'delivered']);
    expect(DB::table('credit_ledger')->where('purchase_id', $purchase)->sum('credits'))->toBe(1);
});

test('review resolution requires an administrator and a waiting period', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture();
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $recipient = DB::table('broadcast_recipients')->first();
    Http::fake(['*' => Http::failedConnection()]);
    (new SendBroadcastRecipient($recipient->id))->handle(app(BroadcastService::class));
    $url = "/workspaces/{$workspace->id}/broadcasts/{$recipient->broadcast_id}/recipients/{$recipient->id}/resolve";
    $this->post($url, ['outcome' => 'failed'])->assertSessionHasErrors('outcome');
    $user = User::factory()->create();
    $user->permissions()->create(['permission' => 'broadcasts.manage']);
    $user->workspaces()->attach($workspace);
    $this->actingAs($user)->post($url, ['outcome' => 'failed'])->assertForbidden();
    $this->travel(3)->minutes();
    $this->actingAs($admin)->post($url, ['outcome' => 'failed'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('broadcast_recipients', ['id' => $recipient->id, 'status' => 'failed', 'reviewed_by' => $admin->id]);
    expect(DB::table('credit_ledger')->where('purchase_id', $purchase)->sum('credits'))->toBe(2);
});

test('audiences are scoped to workspace groups and only opted-in contacts', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture(3);
    $otherWorkspace = Workspace::factory()->create();
    $group = $workspace->contactGroups()->create(['name' => 'Selected']);
    $foreign = $otherWorkspace->contactGroups()->create(['name' => 'Other']);
    $selected = Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $selected->groups()->attach($group);
    $optedOut = Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => false]);
    $optedOut->groups()->attach($group);
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $data['audience'] = 'groups';
    $data['classification_ids'] = [$foreign->id];
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasErrors('classification_ids');
    $data['classification_ids'] = [$group->id];
    $this->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $this->assertDatabaseCount('broadcast_recipients', 1);
    $this->assertDatabaseHas('broadcast_recipients', ['contact_id' => $selected->id]);
    $this->get("/workspaces/{$workspace->id}/broadcasts")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Broadcasts')->has('eligibleContacts', 2));
});

test('scheduler dispatches only due recipients and webhook events update reports', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture();
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $data['scheduled_at'] = now()->addHour()->toIso8601String();
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $recipient = DB::table('broadcast_recipients')->first();
    Queue::fake();
    $this->artisan('broadcasts:dispatch')->assertSuccessful();
    Queue::assertNothingPushed();
    $this->travel(61)->minutes();
    $this->artisan('broadcasts:dispatch')->assertSuccessful();
    Queue::assertPushed(SendBroadcastRecipient::class, fn ($job) => $job->recipientId === $recipient->id);
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.3']]])]);
    (new SendBroadcastRecipient($recipient->id))->handle(app(BroadcastService::class));
    $payload = ['entry' => [['id' => '123', 'changes' => [['field' => 'messages', 'value' => [
        'metadata' => ['phone_number_id' => '456'], 'statuses' => [['id' => 'wamid.3', 'status' => 'read', 'timestamp' => '123', 'recipient_id' => ltrim($recipient->phone, '+')]],
    ]]]]]];
    (new ProcessWhatsAppWebhook($payload, '123', '456'))->handle();
    $this->assertDatabaseHas('broadcast_recipients', ['id' => $recipient->id, 'status' => 'read']);
    $this->get("/workspaces/{$workspace->id}/broadcasts/{$recipient->broadcast_id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('BroadcastReport')->where('recipients.data.0.status', 'read'));
});

test('rate limit responses are delayed and retried at most three times', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture();
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $recipient = DB::table('broadcast_recipients')->first();
    Http::fake(['*' => Http::response(['error' => 'rate limit'], 429)]);
    $job = new SendBroadcastRecipient($recipient->id);
    $job->handle(app(BroadcastService::class));
    $job->handle(app(BroadcastService::class));
    Http::assertSentCount(1);
    $this->travel(3)->minutes();
    $job->handle(app(BroadcastService::class));
    $this->travel(3)->minutes();
    $job->handle(app(BroadcastService::class));
    Http::assertSentCount(3);
    $this->assertDatabaseHas('broadcast_recipients', ['id' => $recipient->id, 'status' => 'failed', 'attempts' => 3]);
    expect(DB::table('credit_ledger')->where('purchase_id', $purchase)->sum('credits'))->toBe(2);
});

test('a late response from an earlier attempt cannot fail a newer reservation', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture();
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $recipient = DB::table('broadcast_recipients')->first();
    Http::fake(function () use ($recipient, $workspace) {
        DB::table('broadcast_recipients')->where('id', $recipient->id)->update(['status' => 'review']);
        app(BroadcastService::class)->fail($recipient->id, 'Confirmed not sent');
        app(BroadcastService::class)->retry($workspace, $recipient->id);
        DB::table('broadcast_recipients')->where('id', $recipient->id)->update(['status' => 'sending', 'attempts' => 1]);

        return Http::response(['error' => 'rejected'], 400);
    });
    (new SendBroadcastRecipient($recipient->id))->handle(app(BroadcastService::class));
    $this->assertDatabaseHas('broadcast_recipients', ['id' => $recipient->id, 'status' => 'sending', 'cycle' => 2]);
    expect(DB::table('credit_ledger')->where('purchase_id', $purchase)->sum('credits'))->toBe(1);
});

test('interrupted sending is marked for review rather than resent', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture();
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $recipient = DB::table('broadcast_recipients')->first();
    DB::table('broadcast_recipients')->where('id', $recipient->id)->update(['status' => 'sending', 'attempted_at' => now()->subMinutes(3)]);
    Queue::fake();
    $this->artisan('broadcasts:dispatch')->assertSuccessful();
    Queue::assertNothingPushed();
    $this->assertDatabaseHas('broadcast_recipients', ['id' => $recipient->id, 'status' => 'review']);
    expect(DB::table('credit_ledger')->where('purchase_id', $purchase)->sum('credits'))->toBe(1);
});

test('external purchase refunds stop pending sends and do not restore spendable credits', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture();
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $recipient = DB::table('broadcast_recipients')->first();
    app(BillingService::class)->recordRefund($purchase, 'external-refund', 100, true);
    Http::fake();
    (new SendBroadcastRecipient($recipient->id))->handle(app(BroadcastService::class));
    Http::assertNothingSent();
    $this->assertDatabaseHas('broadcast_recipients', ['id' => $recipient->id, 'status' => 'failed']);
    $this->assertDatabaseHas('billing_purchases', ['id' => $purchase, 'status' => 'review']);
    expect(DB::table('credit_ledger')->where('purchase_id', $purchase)->sum('credits'))->toBe(0);
});

test('a template without variables is sent without an empty components array', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture();
    WhatsAppTemplate::query()->whereKey($data['template_id'])->update(['components' => [['type' => 'BODY', 'text' => 'Hello']]]);
    $data['mapping'] = [];
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $recipient = DB::table('broadcast_recipients')->first();
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.static']]])]);
    (new SendBroadcastRecipient($recipient->id))->handle(app(BroadcastService::class));
    Http::assertSent(fn ($request) => ! array_key_exists('components', $request['template']));
});

test('a late delivery reverses an expired refund even if its credit was reused', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture(1);
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $recipient = DB::table('broadcast_recipients')->first();
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.late']]])]);
    (new SendBroadcastRecipient($recipient->id))->handle(app(BroadcastService::class));
    DB::table('billing_purchases')->where('id', $purchase)->update(['expires_at' => now()->subSecond()]);
    app(BroadcastService::class)->fail($recipient->id, 'Delivery failed');
    $data['request_id'] = (string) Str::uuid();
    $this->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $this->assertDatabaseHas('broadcast_credit_grants', ['source_recipient_id' => $recipient->id, 'balance' => 0]);
    DB::table('whatsapp_events')->insert([
        'event_key' => str_repeat('c', 64), 'business_account_id' => '123', 'phone_number_id' => '456',
        'type' => 'status', 'message_id' => 'wamid.late', 'status' => 'delivered', 'event_timestamp' => '123',
        'payload' => '{}', 'context' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(BroadcastService::class)->reconcile('wamid.late', '456');
    app(BroadcastService::class)->reconcile('wamid.late', '456');
    $this->assertDatabaseHas('broadcast_credit_grants', ['source_recipient_id' => $recipient->id, 'balance' => -1]);
    $data['request_id'] = (string) Str::uuid();
    $this->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasErrors('audience');
    app(BillingService::class)->recordRefund($purchase, 'late-external-refund', 100, true);
    $this->get('/settings/billing')->assertInertia(fn ($page) => $page->where('balances.0.credits', -1));
});

test('external payment refunds revoke reusable expired credits', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture(1);
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $recipient = DB::table('broadcast_recipients')->first();
    DB::table('billing_purchases')->where('id', $purchase)->update(['expires_at' => now()->subSecond()]);
    app(BroadcastService::class)->fail($recipient->id, 'Failed');
    app(BillingService::class)->recordRefund($purchase, 'expired-external-refund', 100, true);
    $this->post("/workspaces/{$workspace->id}/broadcasts/{$recipient->broadcast_id}/recipients/{$recipient->id}/retry")->assertSessionHasErrors('retry');
    $this->get('/settings/billing')->assertInertia(fn ($page) => $page->has('balances', 0));
});

test('a reused credit is charged once if a failed delivery is later confirmed', function () {
    [$admin, $workspace, $purchase, $data] = broadcastFixture(1);
    Contact::factory()->create(['workspace_id' => $workspace->id, 'opted_in' => true]);
    $this->actingAs($admin)->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $original = DB::table('broadcast_recipients')->first();
    DB::table('billing_purchases')->where('id', $purchase)->update(['expires_at' => now()->subSecond()]);
    app(BroadcastService::class)->fail($original->id, 'Failed');
    $data['request_id'] = (string) Str::uuid();
    $this->post("/workspaces/{$workspace->id}/broadcasts", $data)->assertSessionHasNoErrors();
    $next = DB::table('broadcast_recipients')->where('id', '!=', $original->id)->first();
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reused']]])]);
    (new SendBroadcastRecipient($next->id))->handle(app(BroadcastService::class));
    app(BroadcastService::class)->fail($next->id, 'Failed again');
    $this->assertDatabaseHas('broadcast_credit_grants', ['source_recipient_id' => $original->id, 'balance' => 1]);
    DB::table('whatsapp_events')->insert([
        'event_key' => str_repeat('d', 64), 'business_account_id' => '123', 'phone_number_id' => '456',
        'type' => 'status', 'message_id' => 'wamid.reused', 'status' => 'read', 'event_timestamp' => '123',
        'payload' => '{}', 'context' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(BroadcastService::class)->reconcile('wamid.reused', '456');
    app(BroadcastService::class)->reconcile('wamid.reused', '456');
    $this->assertDatabaseHas('broadcast_credit_grants', ['source_recipient_id' => $original->id, 'balance' => 0]);
    $this->assertDatabaseHas('broadcast_recipients', ['id' => $next->id, 'status' => 'read']);
});

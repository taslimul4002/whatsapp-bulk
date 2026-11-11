<?php

use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppEvent;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function whatsappCredentials(): array
{
    return [
        'business_account_id' => '123',
        'phone_number_id' => '456',
        'api_version' => 'v22.0',
        'access_token' => 'access-secret',
        'app_secret' => 'app-secret',
        'verify_token' => 'verify-secret',
    ];
}

function whatsappAccount(): WhatsAppAccount
{
    $account = new WhatsAppAccount;
    $account->id = 1;
    $account->forceFill(whatsappCredentials())->save();

    return $account;
}

function whatsappPayload(): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '123',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'metadata' => ['phone_number_id' => '456'],
                    'contacts' => [['wa_id' => '100', 'profile' => ['name' => 'Example']]],
                    'messages' => [['id' => 'wamid.in', 'timestamp' => '1700000000', 'from' => '100', 'type' => 'text', 'text' => ['body' => 'Hello']]],
                    'statuses' => [['id' => 'wamid.out', 'status' => 'delivered', 'timestamp' => '1700000001', 'recipient_id' => '100']],
                ],
            ]],
        ]],
    ];
}

function sendWhatsAppWebhook(array $payload, string $secret = 'app-secret'): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return test()->call('POST', '/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $body);
}

test('only administrators can manage and test the account', function () {
    $this->get('/settings/whatsapp')->assertRedirect('/login');
    $this->put('/settings/whatsapp', whatsappCredentials())->assertRedirect('/login');
    $this->post('/settings/whatsapp/test')->assertRedirect('/login');
    $this->actingAs(User::factory()->create());
    $this->get('/settings/whatsapp')->assertForbidden();
    $this->put('/settings/whatsapp', whatsappCredentials())->assertForbidden();
    $this->post('/settings/whatsapp/test')->assertForbidden();
    $this->assertDatabaseCount('whatsapp_accounts', 0);
});

test('credentials are encrypted hidden and preserved when omitted on update', function () {
    $admin = User::factory()->create(['type' => 'admin']);
    $this->actingAs($admin)->put('/settings/whatsapp', whatsappCredentials())->assertSessionHasNoErrors();

    $stored = DB::table('whatsapp_accounts')->first();
    expect($stored->access_token)->not->toBe('access-secret');
    expect($stored->app_secret)->not->toBe('app-secret');
    expect($stored->verify_token)->not->toBe('verify-secret');
    expect(WhatsAppAccount::find(1)->access_token)->toBe('access-secret');
    expect(WhatsAppAccount::find(1)->toArray())->not->toHaveKeys(['access_token', 'app_secret', 'verify_token']);
    $this->get('/settings/whatsapp')->assertInertia(fn (Assert $page) => $page
        ->component('WhatsAppSettings')
        ->where('account.phone_number_id', '456')
        ->missing('account.access_token')->missing('account.app_secret')->missing('account.verify_token'));

    $this->put('/settings/whatsapp', [
        'business_account_id' => '123',
        'phone_number_id' => '789',
        'api_version' => 'v22.0',
        'access_token' => '',
        'app_secret' => '',
        'verify_token' => '',
    ])->assertSessionHasNoErrors();
    expect(WhatsAppAccount::find(1)->access_token)->toBe('access-secret');
    expect(WhatsAppAccount::find(1)->app_secret)->toBe('app-secret');
    expect(WhatsAppAccount::find(1)->verify_token)->toBe('verify-secret');
    $this->assertDatabaseHas('whatsapp_accounts', ['id' => 1, 'phone_number_id' => '789']);
});

test('account settings validate input without flashing secrets', function () {
    $admin = User::factory()->create(['type' => 'admin']);
    $this->actingAs($admin)->put('/settings/whatsapp', [
        ...whatsappCredentials(),
        'phone_number_id' => '../invalid',
        'api_version' => 'v1/unsafe',
    ])->assertSessionHasErrors(['phone_number_id', 'api_version'])
        ->assertSessionMissing('_old_input.access_token')
        ->assertSessionMissing('_old_input.app_secret')
        ->assertSessionMissing('_old_input.verify_token');
    $this->put('/settings/whatsapp', [])->assertSessionHasErrors([
        'business_account_id', 'phone_number_id', 'api_version', 'access_token', 'app_secret', 'verify_token',
    ]);
    $this->put('/settings/whatsapp', [...whatsappCredentials(), 'access_token' => "bad\r\ntoken"])
        ->assertSessionHasErrors('access_token');
    $this->assertDatabaseCount('whatsapp_accounts', 0);
});

test('connection tests use saved credentials and report Meta errors', function () {
    $admin = User::factory()->create(['type' => 'admin']);
    whatsappAccount();
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/v22.0/456*' => Http::response(['id' => '456'])]);

    $this->actingAs($admin)->post('/settings/whatsapp/test')->assertSessionHas('success', 'flash.whatsapp_connected');
    Http::assertSent(fn (ClientRequest $request) => $request->hasHeader('Authorization', 'Bearer access-secret')
        && $request->url() === 'https://graph.facebook.com/v22.0/456?fields=id');
});

test('connection test reports unsuccessful and unreachable services', function (bool $unreachable) {
    $admin = User::factory()->create(['type' => 'admin']);
    whatsappAccount();
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/v22.0/456*' => $unreachable ? Http::failedConnection() : Http::response(['error' => 'invalid'], 401)]);

    $this->actingAs($admin)->post('/settings/whatsapp/test')
        ->assertSessionHasErrors(['connection' => $unreachable ? 'whatsapp.unreachable' : 'whatsapp.connection_failed']);
})->with([false, true]);

test('Meta verification requires the correct token and returns the raw challenge', function () {
    whatsappAccount();

    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-secret&hub.challenge=12345')
        ->assertOk()->assertContent('12345');
    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')->assertForbidden();
    $this->get('/webhooks/whatsapp?hub.mode=other&hub.verify_token=verify-secret&hub.challenge=12345')->assertForbidden();
});

test('webhooks reject invalid signatures and malformed events without queuing them', function () {
    whatsappAccount();
    Queue::fake();

    sendWhatsAppWebhook(whatsappPayload(), 'wrong')->assertForbidden();
    $this->postJson('/webhooks/whatsapp', whatsappPayload())->assertForbidden();
    $invalid = whatsappPayload();
    unset($invalid['entry'][0]['changes'][0]['value']['statuses'][0]['timestamp']);
    sendWhatsAppWebhook($invalid)->assertBadRequest();
    Queue::assertNothingPushed();
});

test('signed webhooks enqueue encrypted processing with account identifiers', function () {
    whatsappAccount();
    Queue::fake();
    $payload = whatsappPayload();

    sendWhatsAppWebhook($payload)->assertOk()->assertContent('EVENT_RECEIVED');
    Queue::assertPushed(ProcessWhatsAppWebhook::class, fn ($job) => $job->payload === $payload
        && $job->businessAccountId === '123' && $job->phoneNumberId === '456'
        && $job instanceof ShouldBeEncrypted);
});

test('database queue encrypts webhook data and a worker persists the events', function () {
    whatsappAccount();
    config(['queue.default' => 'database']);

    sendWhatsAppWebhook(whatsappPayload())->assertOk();
    $job = DB::table('jobs')->first();
    expect($job)->not->toBeNull();
    expect($job->payload)->not->toContain('wamid.in', 'Hello');
    $this->assertDatabaseCount('whatsapp_events', 0);

    $this->artisan('queue:work', ['--once' => true, '--no-interaction' => true])->assertSuccessful();
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('whatsapp_events', 2);
});

test('queued processing persists each message and status once and ignores other accounts', function () {
    whatsappAccount();
    config(['queue.default' => 'sync']);
    $payload = whatsappPayload();
    $payload['entry'][] = ['id' => 'different', 'changes' => $payload['entry'][0]['changes']];
    $otherPhone = $payload['entry'][0]['changes'][0];
    $otherPhone['value']['metadata']['phone_number_id'] = '999';
    $payload['entry'][0]['changes'][] = $otherPhone;

    sendWhatsAppWebhook($payload)->assertOk();
    sendWhatsAppWebhook($payload)->assertOk();
    $this->assertDatabaseCount('whatsapp_events', 2);
    $this->assertDatabaseHas('whatsapp_events', ['type' => 'message', 'message_id' => 'wamid.in', 'phone_number_id' => '456']);
    $this->assertDatabaseHas('whatsapp_events', ['type' => 'status', 'message_id' => 'wamid.out', 'status' => 'delivered']);
    expect(WhatsAppEvent::where('type', 'message')->first()->payload['text']['body'])->toBe('Hello');
    expect(WhatsAppEvent::where('type', 'message')->first()->context['contacts'][0]['profile']['name'])->toBe('Example');

    $payload['entry'][0]['changes'][0]['value']['statuses'][0]['status'] = 'read';
    $payload['entry'][0]['changes'][0]['value']['statuses'][0]['timestamp'] = '1700000002';
    sendWhatsAppWebhook($payload)->assertOk();
    $this->assertDatabaseCount('whatsapp_events', 3);
    $this->assertDatabaseHas('whatsapp_events', ['type' => 'status', 'message_id' => 'wamid.out', 'status' => 'read']);
});

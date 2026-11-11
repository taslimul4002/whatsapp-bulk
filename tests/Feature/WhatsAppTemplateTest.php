<?php

use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function templateAccount(): WhatsAppAccount
{
    $account = new WhatsAppAccount;
    $account->id = 1;
    $account->forceFill([
        'business_account_id' => '123', 'phone_number_id' => '456', 'api_version' => 'v22.0',
        'access_token' => 'secret', 'app_secret' => 'app-secret', 'verify_token' => 'verify',
    ])->save();

    return $account;
}

function templateInput(): array
{
    return [
        'name' => 'order_update', 'language' => 'en_US', 'category' => 'UTILITY',
        'header_format' => 'IMAGE', 'header_text' => '', 'header_example' => 'uploaded-handle',
        'body' => 'Hello {{1}}, order {{2}}', 'body_examples' => ['Jane', '123'],
        'footer' => 'Thank you', 'buttons' => [
            ['type' => 'URL', 'text' => 'View order', 'value' => 'https://example.com/order'],
            ['type' => 'PHONE_NUMBER', 'text' => 'Call', 'value' => '+12345678901'],
            ['type' => 'QUICK_REPLY', 'text' => 'Thanks', 'value' => ''],
        ],
    ];
}

function remoteTemplate(array $changes = []): array
{
    return array_replace([
        'id' => '987', 'name' => 'order_update', 'language' => 'en_US',
        'category' => 'UTILITY', 'status' => 'APPROVED',
        'components' => [['type' => 'BODY', 'text' => 'Approved']],
    ], $changes);
}

test('template endpoints require administrators', function () {
    $template = WhatsAppTemplate::factory()->create();
    $this->get('/templates')->assertRedirect('/login');
    $this->actingAs(User::factory()->create());
    $this->get('/templates')->assertForbidden();
    $this->post('/templates', templateInput())->assertForbidden();
    $this->post('/templates/sync')->assertForbidden();
    $this->post("/templates/{$template->id}/submit")->assertForbidden();
    $this->post("/templates/{$template->id}/recover")->assertForbidden();
    $this->put("/templates/{$template->id}", templateInput())->assertForbidden();
    $this->delete("/templates/{$template->id}")->assertForbidden();
});

test('administrators can create edit preview and delete rich drafts', function () {
    templateAccount();
    $this->actingAs(User::factory()->create(['type' => 'admin']));
    $this->post('/templates', templateInput())->assertSessionHasNoErrors();
    $template = WhatsAppTemplate::firstOrFail();
    expect($template->components)->toBe([
        ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => ['uploaded-handle']]],
        ['type' => 'BODY', 'text' => 'Hello {{1}}, order {{2}}', 'example' => ['body_text' => [['Jane', '123']]]],
        ['type' => 'FOOTER', 'text' => 'Thank you'],
        ['type' => 'BUTTONS', 'buttons' => [
            ['type' => 'URL', 'text' => 'View order', 'url' => 'https://example.com/order'],
            ['type' => 'PHONE_NUMBER', 'text' => 'Call', 'phone_number' => '+12345678901'],
            ['type' => 'QUICK_REPLY', 'text' => 'Thanks'],
        ]],
    ]);
    $this->get('/templates')->assertInertia(fn (Assert $page) => $page->component('Templates')->where('templates.0.components.1.text', 'Hello {{1}}, order {{2}}'));
    $this->put("/templates/{$template->id}", [...templateInput(), 'body' => 'Updated', 'body_examples' => []])->assertSessionHasNoErrors();
    expect($template->fresh()->components[1]['text'])->toBe('Updated');
    $this->delete("/templates/{$template->id}")->assertSessionHasNoErrors();
    $this->assertModelMissing($template);
});

test('draft validation rejects invalid examples media and buttons', function () {
    templateAccount();
    $this->actingAs(User::factory()->create(['type' => 'admin']))
        ->post('/templates', [...templateInput(), 'header_example' => '', 'body_examples' => ['one'], 'buttons' => [['type' => 'URL', 'text' => 'Link', 'value' => 'javascript:alert(1)']]])
        ->assertSessionHasErrors(['header_example', 'body_examples', 'buttons.0.value']);
    $this->assertDatabaseCount('whats_app_templates', 0);
});

test('text headers include a variable example', function () {
    templateAccount();
    $this->actingAs(User::factory()->create(['type' => 'admin']))
        ->post('/templates', [...templateInput(), 'header_format' => 'TEXT', 'header_text' => 'Hi {{1}}', 'header_example' => 'Jane'])
        ->assertSessionHasNoErrors();
    expect(WhatsAppTemplate::firstOrFail()->components[0])->toBe([
        'type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Hi {{1}}', 'example' => ['header_text' => ['Jane']],
    ]);
});

test('submission sends rich components and locks submitted records', function () {
    templateAccount();
    $template = WhatsAppTemplate::factory()->create(['components' => [['type' => 'BODY', 'text' => 'Hello']]]);
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/v22.0/123/message_templates' => Http::response(['id' => '987', 'status' => 'PENDING'])]);
    $this->actingAs(User::factory()->create(['type' => 'admin']))->post("/templates/{$template->id}/submit")->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['components'][0]['text'] === 'Hello' && $request['name'] === $template->name);
    $this->assertDatabaseHas('whats_app_templates', ['id' => $template->id, 'meta_id' => '987', 'status' => 'PENDING']);
    $this->delete("/templates/{$template->id}")->assertConflict();
    $this->put("/templates/{$template->id}", templateInput())->assertConflict();
    $this->post("/templates/{$template->id}/submit")->assertConflict();
});

test('uncertain submission is not retried as a draft', function () {
    templateAccount();
    $template = WhatsAppTemplate::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/v22.0/123/message_templates' => Http::failedConnection()]);
    $this->actingAs(User::factory()->create(['type' => 'admin']))->post("/templates/{$template->id}/submit")
        ->assertSessionHasErrors(['meta' => 'templates.submit_unknown']);
    $this->assertDatabaseHas('whats_app_templates', ['id' => $template->id, 'status' => 'SUBMISSION_UNKNOWN']);
    $this->post("/templates/{$template->id}/submit")->assertConflict();
    Http::assertSentCount(1);
});

test('a rejected submission leaves the draft editable', function () {
    templateAccount();
    $template = WhatsAppTemplate::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/v22.0/123/message_templates' => Http::response(['error' => ['code' => 100]], 400)]);
    $this->actingAs(User::factory()->create(['type' => 'admin']))->post("/templates/{$template->id}/submit")
        ->assertSessionHasErrors(['meta' => 'templates.submit_failed']);
    $this->assertDatabaseHas('whats_app_templates', ['id' => $template->id, 'status' => 'DRAFT']);
    Http::assertSentCount(1);
});

test('recovery requires an uncertain or old in-flight submission', function () {
    templateAccount();
    $template = WhatsAppTemplate::factory()->create(['status' => 'SUBMITTING']);
    $this->actingAs(User::factory()->create(['type' => 'admin']))
        ->post("/templates/{$template->id}/recover")->assertSessionHasErrors(['meta' => 'templates.recover_unavailable']);
    $template->forceFill(['status' => 'SUBMISSION_UNKNOWN'])->save();
    $this->post("/templates/{$template->id}/recover")->assertSessionHasNoErrors();
    $this->assertDatabaseHas('whats_app_templates', ['id' => $template->id, 'status' => 'DRAFT']);
});

test('sync fetches all pages and reconciles uncertain drafts', function () {
    templateAccount();
    $template = WhatsAppTemplate::factory()->create(['name' => 'order_update', 'status' => 'SUBMISSION_UNKNOWN']);
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/v22.0/123/message_templates*' => Http::sequence()
        ->push(['data' => [remoteTemplate()], 'paging' => ['next' => 'https://example.invalid/', 'cursors' => ['after' => 'cursor']]])
        ->push(['data' => [remoteTemplate(['id' => '988', 'name' => 'other'])]])]);
    $this->actingAs(User::factory()->create(['type' => 'admin']))->post('/templates/sync')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('whats_app_templates', ['id' => $template->id, 'meta_id' => '987', 'status' => 'APPROVED']);
    $this->assertDatabaseHas('whats_app_templates', ['meta_id' => '988', 'name' => 'other']);
    Http::assertSentCount(2);
});

test('sync failure imports no partial pages', function () {
    templateAccount();
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/v22.0/123/message_templates*' => Http::sequence()
        ->push(['data' => [remoteTemplate()], 'paging' => ['next' => 'next', 'cursors' => ['after' => 'cursor']]])
        ->push(['error' => 'failure'], 500)]);
    $this->actingAs(User::factory()->create(['type' => 'admin']))->post('/templates/sync')->assertSessionHasErrors(['meta' => 'templates.sync_failed']);
    $this->assertDatabaseCount('whats_app_templates', 0);
    Http::assertSentCount(2);
});

test('signed status webhooks update only matching accounts and ignore older events', function () {
    templateAccount();
    $template = WhatsAppTemplate::factory()->create(['meta_id' => '987', 'status' => 'PENDING']);
    Queue::fake();
    $payload = ['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => '123', 'time' => 100, 'changes' => [[
            'field' => 'message_template_status_update',
            'value' => ['message_template_id' => 987, 'event' => 'REJECTED', 'reason' => 'INVALID_FORMAT'],
        ]],
    ]]];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $this->call('POST', '/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
    ], $body)->assertOk();
    Queue::assertPushed(ProcessWhatsAppWebhook::class, fn ($job) => $job->payload === $payload);
    (new ProcessWhatsAppWebhook($payload, '123', '456'))->handle();
    $this->assertDatabaseHas('whats_app_templates', ['id' => $template->id, 'status' => 'REJECTED', 'reason' => 'INVALID_FORMAT']);
    $payload['entry'][0]['time'] = 99;
    $payload['entry'][0]['changes'][0]['value']['event'] = 'APPROVED';
    (new ProcessWhatsAppWebhook($payload, '123', '456'))->handle();
    $this->assertDatabaseHas('whats_app_templates', ['id' => $template->id, 'status' => 'REJECTED']);
    $payload['entry'][0]['id'] = '999';
    $payload['entry'][0]['time'] = 101;
    (new ProcessWhatsAppWebhook($payload, '123', '456'))->handle();
    $this->assertDatabaseHas('whats_app_templates', ['id' => $template->id, 'status' => 'REJECTED']);
});

test('invalid template status webhooks are rejected without queuing', function () {
    templateAccount();
    Queue::fake();
    $payload = ['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => '123', 'changes' => [[
            'field' => 'message_template_status_update',
            'value' => ['message_template_id' => 987, 'event' => 'APPROVED'],
        ]],
    ]]];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $this->call('POST', '/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
    ], $body)->assertBadRequest();
    Queue::assertNothingPushed();
});

test('records belonging to a previous account cannot be modified', function () {
    templateAccount();
    $template = WhatsAppTemplate::factory()->create(['business_account_id' => '999']);
    $this->actingAs(User::factory()->create(['type' => 'admin']))->delete("/templates/{$template->id}")->assertNotFound();
    $this->post("/templates/{$template->id}/submit")->assertNotFound();
    $this->assertModelExists($template);
});

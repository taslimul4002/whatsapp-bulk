<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\WhatsAppAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use JsonException;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $account = WhatsAppAccount::find(1);
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');
        abort_unless($account && $request->query('hub_mode') === 'subscribe' && is_string($token)
            && is_string($challenge) && hash_equals($account->verify_token, $token), 403);

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request): Response
    {
        $account = WhatsAppAccount::find(1);
        $signature = $request->header('X-Hub-Signature-256');
        abort_unless($account && is_string($signature)
            && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $account->app_secret), $signature), 403);

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            abort(400, 'Invalid JSON payload.');
        }

        abort_unless(is_array($payload), 400, 'Invalid webhook payload.');
        $validator = Validator::make($payload, [
            'object' => ['required', 'in:whatsapp_business_account'],
            'entry' => ['required', 'array'],
            'entry.*.id' => ['required', 'string'],
            'entry.*.changes' => ['required', 'array'],
            'entry.*.changes.*.field' => ['required', 'string'],
            'entry.*.changes.*.value' => ['required', 'array'],
            'entry.*.changes.*.value.metadata' => ['sometimes', 'array'],
            'entry.*.changes.*.value.metadata.phone_number_id' => ['sometimes', 'string'],
            'entry.*.changes.*.value.messages' => ['sometimes', 'array'],
            'entry.*.changes.*.value.messages.*.id' => ['required', 'string', 'max:255'],
            'entry.*.changes.*.value.messages.*.timestamp' => ['sometimes', 'string', 'max:32'],
            'entry.*.changes.*.value.statuses' => ['sometimes', 'array'],
            'entry.*.changes.*.value.statuses.*.id' => ['required', 'string', 'max:255'],
            'entry.*.changes.*.value.statuses.*.status' => ['required', 'string', 'max:32'],
            'entry.*.changes.*.value.statuses.*.timestamp' => ['required', 'string', 'max:32'],
            'entry.*.changes.*.value.statuses.*.recipient_id' => ['required', 'string'],
        ]);
        abort_if($validator->fails(), 400, 'Invalid webhook payload.');

        foreach ($payload['entry'] as $entry) {
            foreach ($entry['changes'] as $change) {
                if ($change['field'] === 'messages' && ! isset($change['value']['metadata']['phone_number_id'])) {
                    abort(400, 'Missing phone number ID.');
                }
                if ($change['field'] === 'message_template_status_update') {
                    $statusValidator = Validator::make([...$change['value'], 'time' => $entry['time'] ?? null], [
                        'time' => ['required', 'integer', 'min:1'],
                        'message_template_id' => ['required', 'regex:/^[0-9]{1,64}$/'],
                        'event' => ['required', 'string', 'max:64', 'regex:/^[A-Z_]+$/'],
                        'reason' => ['nullable', 'string', 'max:1000'],
                    ]);
                    abort_if($statusValidator->fails(), 400, 'Invalid template status update.');
                }
            }
        }

        ProcessWhatsAppWebhook::dispatch($payload, $account->business_account_id, $account->phone_number_id);

        return response('EVENT_RECEIVED', 200)->header('Content-Type', 'text/plain');
    }
}

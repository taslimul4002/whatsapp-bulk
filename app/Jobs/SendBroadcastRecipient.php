<?php

namespace App\Jobs;

use App\Models\WhatsAppAccount;
use App\Support\BroadcastService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendBroadcastRecipient implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    public function __construct(public int $recipientId) {}

    public function handle(BroadcastService $service): void
    {
        $claim = DB::transaction(function (): ?array {
            $recipient = DB::table('broadcast_recipients')->where('id', $this->recipientId)->lockForUpdate()->first();
            if (! $recipient || $recipient->status !== 'pending' || ($recipient->next_attempt_at && now()->lt($recipient->next_attempt_at))) {
                return null;
            }
            $broadcast = DB::table('broadcasts')->where('id', $recipient->broadcast_id)->first();
            if (now()->lt($broadcast->scheduled_at)) {
                return null;
            }
            $account = WhatsAppAccount::query()->whereKey(1)->lockForUpdate()->first();
            $purchase = DB::table('billing_purchases')->where('id', $recipient->purchase_id)->lockForUpdate()->first();
            $contact = DB::table('contacts')->where('id', $recipient->contact_id)->where('workspace_id', $broadcast->workspace_id)->lockForUpdate()->first();
            $templateApproved = DB::table('whats_app_templates')->where('business_account_id', $broadcast->business_account_id)
                ->where('name', $broadcast->template_name)->where('language', $broadcast->template_language)->where('status', 'APPROVED')->lockForUpdate()->first();
            if (! $account || $account->phone_number_id !== $broadcast->phone_number_id || $account->business_account_id !== $broadcast->business_account_id
                || ! $contact || ! $contact->opted_in || $contact->phone !== $recipient->phone || $purchase->status !== 'paid' || ! $templateApproved) {
                DB::table('broadcast_recipients')->where('id', $recipient->id)->update(['status' => 'sending', 'attempted_at' => now(), 'updated_at' => now()]);

                return ['failure' => 'Recipient, account, or credit purchase is no longer eligible.', 'cycle' => $recipient->cycle, 'attempts' => $recipient->attempts];
            }
            if (DB::table('broadcast_send_attempts')->where('phone_number_id', $account->phone_number_id)->where('created_at', '>', now()->subMinute())->lockForUpdate()->get(['id'])->count() >= 60) {
                return null;
            }
            DB::table('broadcast_send_attempts')->insert(['phone_number_id' => $account->phone_number_id, 'created_at' => now()]);
            DB::table('broadcast_recipients')->where('id', $recipient->id)->update([
                'status' => 'sending', 'attempts' => $recipient->attempts + 1, 'attempted_at' => now(), 'updated_at' => now(),
            ]);

            return ['recipient' => $recipient, 'broadcast' => $broadcast, 'account' => $account];
        }, 3);
        if (! $claim) {
            return;
        }
        if (isset($claim['failure'])) {
            $service->fail($this->recipientId, $claim['failure'], $claim['cycle'], 'sending', $claim['attempts']);

            return;
        }
        $account = $claim['account'];
        $broadcast = $claim['broadcast'];
        $recipient = $claim['recipient'];
        $attempt = $recipient->attempts + 1;
        $components = json_decode($recipient->components, true, 512, JSON_THROW_ON_ERROR);
        try {
            $response = Http::withToken($account->access_token)->connectTimeout(5)->timeout(20)
                ->post("https://graph.facebook.com/{$account->api_version}/{$account->phone_number_id}/messages", [
                    'messaging_product' => 'whatsapp', 'to' => ltrim($recipient->phone, '+'), 'type' => 'template',
                    'template' => [
                        'name' => $broadcast->template_name, 'language' => ['code' => $broadcast->template_language],
                        ...($components ? ['components' => $components] : []),
                    ],
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Broadcast send outcome is unknown.', ['recipient_id' => $recipient->id]);
            DB::table('broadcast_recipients')->where('id', $recipient->id)->where('cycle', $recipient->cycle)->where('attempts', $attempt)->where('status', 'sending')
                ->update(['status' => 'review', 'error' => 'Connection failed; delivery is unknown.', 'updated_at' => now()]);

            return;
        }
        $messageId = $response->json('messages.0.id');
        if ($response->successful() && is_string($messageId) && $messageId !== '' && strlen($messageId) <= 255) {
            DB::table('broadcast_recipients')->where('id', $recipient->id)->where('cycle', $recipient->cycle)->where('attempts', $attempt)->where('status', 'sending')
                ->update(['status' => 'sent', 'message_id' => $messageId, 'error' => null, 'updated_at' => now()]);
            $service->reconcile($messageId, $broadcast->phone_number_id);
        } elseif ($response->status() === 429 && $recipient->attempts < 2) {
            DB::table('broadcast_recipients')->where('id', $recipient->id)->where('cycle', $recipient->cycle)->where('attempts', $attempt)->where('status', 'sending')
                ->update(['status' => 'pending', 'next_attempt_at' => now()->addMinutes(2), 'error' => 'Rate limited by Meta.', 'updated_at' => now()]);
        } elseif ($response->status() >= 400 && $response->status() < 500 && $response->status() !== 408) {
            $service->fail($recipient->id, 'Meta rejected the message (HTTP '.$response->status().').', $recipient->cycle, 'sending', $attempt);
        } else {
            Log::warning('Broadcast send outcome is unknown.', ['recipient_id' => $recipient->id, 'http_status' => $response->status()]);
            DB::table('broadcast_recipients')->where('id', $recipient->id)->where('cycle', $recipient->cycle)->where('attempts', $attempt)->where('status', 'sending')
                ->update(['status' => 'review', 'error' => 'Meta response did not confirm delivery.', 'updated_at' => now()]);
        }
    }
}

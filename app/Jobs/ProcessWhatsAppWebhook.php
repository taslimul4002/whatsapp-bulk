<?php

namespace App\Jobs;

use App\Models\WhatsAppEvent;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWhatsAppWebhook implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public array $backoff = [5, 30, 120];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
        public string $businessAccountId,
        public string $phoneNumberId,
    ) {}

    public function handle(): void
    {
        foreach ($this->payload['entry'] as $entry) {
            if ($entry['id'] !== $this->businessAccountId) {
                continue;
            }

            foreach ($entry['changes'] as $change) {
                $value = $change['value'];
                if ($change['field'] !== 'messages' || ($value['metadata']['phone_number_id'] ?? null) !== $this->phoneNumberId) {
                    continue;
                }

                foreach (['messages' => 'message', 'statuses' => 'status'] as $key => $type) {
                    foreach ($value[$key] ?? [] as $item) {
                        $identity = $type === 'message'
                            ? [$this->businessAccountId, $this->phoneNumberId, $type, $item['id']]
                            : [$this->businessAccountId, $this->phoneNumberId, $type, $item['id'], $item['status'], $item['timestamp'], $item['recipient_id']];

                        WhatsAppEvent::query()->createOrFirst(
                            ['event_key' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR))],
                            [
                                'business_account_id' => $this->businessAccountId,
                                'phone_number_id' => $this->phoneNumberId,
                                'type' => $type,
                                'message_id' => $item['id'],
                                'status' => $type === 'status' ? $item['status'] : null,
                                'event_timestamp' => $item['timestamp'] ?? null,
                                'payload' => $item,
                                'context' => array_diff_key($value, array_flip(['messages', 'statuses'])),
                            ],
                        );
                    }
                }
            }
        }
    }
}

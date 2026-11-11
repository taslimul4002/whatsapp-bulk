<?php

namespace App\Support;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BroadcastService
{
    public function create(Workspace $workspace, int $userId, array $data): int
    {
        return DB::transaction(function () use ($workspace, $userId, $data): int {
            DB::table('workspaces')->where('id', $workspace->id)->lockForUpdate()->first();
            $existing = DB::table('broadcasts')->where('request_id', $data['request_id'])->first();
            if ($existing) {
                if ((int) $existing->workspace_id !== (int) $workspace->id || (int) $existing->created_by !== $userId) {
                    throw ValidationException::withMessages(['request_id' => 'This request has already been used.']);
                }

                return $existing->id;
            }

            $account = WhatsAppAccount::find(1);
            $template = WhatsAppTemplate::query()->whereKey($data['template_id'])->where('status', 'APPROVED')
                ->where('business_account_id', $account?->business_account_id)->first();
            if (! $account || ! $template) {
                throw ValidationException::withMessages(['template_id' => 'Select an approved template for the configured account.']);
            }
            $variables = $this->variables($template);
            if (count($variables) !== count($data['mapping'])) {
                throw ValidationException::withMessages(['mapping' => 'Map every template variable.']);
            }

            $contacts = $workspace->contacts()->where('opted_in', true)
                ->when($data['audience'] === 'groups', fn ($query) => $query->whereHas('groups', fn ($query) => $query->whereIn('contact_groups.id', $data['classification_ids'])))
                ->when($data['audience'] === 'tags', fn ($query) => $query->whereHas('tags', fn ($query) => $query->whereIn('contact_tags.id', $data['classification_ids'])))
                ->orderBy('id')->get();
            if ($contacts->isEmpty()) {
                throw ValidationException::withMessages(['audience' => 'No opted-in contacts match this audience.']);
            }

            $balances = $this->availableBalances($workspace->id);
            if (array_sum(array_column($balances, 'balance')) < $contacts->count()) {
                throw ValidationException::withMessages(['audience' => 'Insufficient available credits.']);
            }

            $id = DB::table('broadcasts')->insertGetId([
                'workspace_id' => $workspace->id, 'created_by' => $userId, 'request_id' => $data['request_id'],
                'name' => $data['name'], 'business_account_id' => $account->business_account_id,
                'phone_number_id' => $account->phone_number_id, 'template_name' => $template->name,
                'template_language' => $template->language, 'scheduled_at' => isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at'])->utc() : now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($contacts as $contact) {
                $components = [];
                foreach ($variables as $index => $variable) {
                    $value = $data['mapping'][$index] === 'name' ? $contact->name : ($data['mapping'][$index] === 'phone' ? $contact->phone : $contact->email);
                    if (! is_string($value) || trim($value) === '') {
                        throw ValidationException::withMessages(['mapping' => 'Every recipient must have a value for each mapped variable.']);
                    }
                    $components[$variable][] = ['type' => 'text', 'text' => $value];
                }
                $payload = [];
                foreach ($components as $type => $parameters) {
                    $payload[] = ['type' => $type, 'parameters' => $parameters];
                }
                $source = $this->takeCredit($balances);
                $recipientId = DB::table('broadcast_recipients')->insertGetId([
                    'broadcast_id' => $id, 'contact_id' => $contact->id, 'purchase_id' => $source['purchase_id'],
                    'credit_grant_id' => $source['grant_id'],
                    'phone' => $contact->phone, 'components' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('credit_ledger')->insert([
                    'workspace_id' => $workspace->id, 'purchase_id' => $source['purchase_id'], 'type' => $source['grant_id'] === null ? 'broadcast_reserve' : 'broadcast_grant_reserve',
                    'idempotency_key' => 'broadcast:'.$recipientId.':1:reserve', 'credits' => $source['grant_id'] === null ? -1 : 0, 'created_at' => now(),
                ]);
            }

            return $id;
        }, 3);
    }

    /** @return list<string> */
    public function variables(WhatsAppTemplate $template): array
    {
        $variables = [];
        foreach ($template->components as $component) {
            $type = strtoupper($component['type'] ?? '');
            if ($type === 'HEADER' && ($component['format'] ?? '') !== 'TEXT') {
                throw ValidationException::withMessages(['template_id' => 'Media headers are not supported for broadcasts.']);
            }
            if (! in_array($type, ['HEADER', 'BODY'], true)) {
                if ($type === 'BUTTONS' && str_contains(json_encode($component, JSON_THROW_ON_ERROR), '{{')) {
                    throw ValidationException::withMessages(['template_id' => 'Dynamic buttons are not supported for broadcasts.']);
                }

                continue;
            }
            preg_match_all('/\{\{([0-9]+)\}\}/', $component['text'] ?? '', $matches);
            $numbers = array_values(array_unique(array_map('intval', $matches[1])));
            sort($numbers);
            if (($numbers && $numbers !== range(1, count($numbers))) || str_contains(preg_replace('/\{\{[0-9]+\}\}/', '', $component['text'] ?? ''), '{{')) {
                throw ValidationException::withMessages(['template_id' => 'This template has unsupported variables.']);
            }
            foreach ($numbers as $_number) {
                $variables[] = strtolower($type);
            }
        }

        return $variables;
    }

    public function fail(int $recipientId, string $error, ?int $expectedCycle = null, ?string $expectedStatus = null, ?int $expectedAttempt = null): void
    {
        DB::transaction(function () use ($recipientId, $error, $expectedCycle, $expectedStatus, $expectedAttempt): void {
            $recipient = DB::table('broadcast_recipients')->where('id', $recipientId)->lockForUpdate()->first();
            if (! $recipient || in_array($recipient->status, ['failed', 'delivered', 'read'], true)
                || ($expectedCycle !== null && (int) $recipient->cycle !== $expectedCycle)
                || ($expectedStatus !== null && $recipient->status !== $expectedStatus)
                || ($expectedAttempt !== null && (int) $recipient->attempts !== $expectedAttempt)) {
                return;
            }
            $purchase = DB::table('billing_purchases')->where('id', $recipient->purchase_id)->lockForUpdate()->first();
            DB::table('broadcast_recipients')->where('id', $recipientId)->update(['status' => 'failed', 'error' => $error, 'updated_at' => now()]);
            $workspaceId = DB::table('broadcasts')->where('id', $recipient->broadcast_id)->value('workspace_id');
            DB::table('credit_ledger')->insertOrIgnore([
                'workspace_id' => $workspaceId, 'purchase_id' => $recipient->purchase_id, 'type' => 'broadcast_refund',
                'idempotency_key' => 'broadcast:'.$recipientId.':'.$recipient->cycle.':refund', 'credits' => $recipient->credit_grant_id === null ? 1 : 0, 'created_at' => now(),
            ]);
            if ($recipient->credit_grant_id !== null && $purchase->status === 'paid') {
                DB::table('broadcast_credit_grants')->where('id', $recipient->credit_grant_id)->increment('balance');
                DB::table('credit_ledger')->insert([
                    'workspace_id' => $workspaceId, 'purchase_id' => $recipient->purchase_id, 'type' => 'broadcast_grant_refund',
                    'idempotency_key' => 'broadcast:'.$recipientId.':'.$recipient->cycle.':grant_refund', 'credits' => 0, 'created_at' => now(),
                ]);
            } elseif ($recipient->credit_grant_id === null && ($purchase->status !== 'paid' || ($purchase->expires_at && now()->gte($purchase->expires_at)))) {
                DB::table('credit_ledger')->insertOrIgnore([
                    'workspace_id' => $workspaceId, 'purchase_id' => $recipient->purchase_id, 'type' => 'broadcast_void',
                    'idempotency_key' => 'broadcast:'.$recipientId.':'.$recipient->cycle.':void', 'credits' => -1, 'created_at' => now(),
                ]);
                if ($purchase->status === 'paid') {
                    DB::table('broadcast_credit_grants')->insert([
                        'purchase_id' => $recipient->purchase_id, 'source_recipient_id' => $recipientId,
                        'source_cycle' => $recipient->cycle, 'balance' => 1, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('credit_ledger')->insert([
                        'workspace_id' => $workspaceId, 'purchase_id' => $recipient->purchase_id, 'type' => 'broadcast_grant_create',
                        'idempotency_key' => 'broadcast:'.$recipientId.':'.$recipient->cycle.':grant_create', 'credits' => 0, 'created_at' => now(),
                    ]);
                }
            }

        }, 3);
    }

    public function retry(Workspace $workspace, int $recipientId): void
    {
        DB::transaction(function () use ($workspace, $recipientId): void {
            $recipient = DB::table('broadcast_recipients')->where('id', $recipientId)->lockForUpdate()->first();
            abort_unless($recipient && DB::table('broadcasts')->where('id', $recipient->broadcast_id)->where('workspace_id', $workspace->id)->exists(), 404);
            if ($recipient->status !== 'failed') {
                throw ValidationException::withMessages(['retry' => 'Only confirmed failures can be retried.']);
            }
            $balances = $this->availableBalances($workspace->id);
            if (array_sum(array_column($balances, 'balance')) < 1) {
                throw ValidationException::withMessages(['retry' => 'There are no available credits.']);
            }
            $source = $this->takeCredit($balances);
            $cycle = $recipient->cycle + 1;
            DB::table('credit_ledger')->insert([
                'workspace_id' => $workspace->id, 'purchase_id' => $source['purchase_id'], 'type' => $source['grant_id'] === null ? 'broadcast_reserve' : 'broadcast_grant_reserve',
                'idempotency_key' => 'broadcast:'.$recipientId.':'.$cycle.':reserve', 'credits' => $source['grant_id'] === null ? -1 : 0, 'created_at' => now(),
            ]);
            DB::table('broadcast_recipients')->where('id', $recipientId)->update([
                'status' => 'pending', 'purchase_id' => $source['purchase_id'], 'credit_grant_id' => $source['grant_id'], 'cycle' => $cycle, 'message_id' => null, 'attempts' => 0,
                'next_attempt_at' => null, 'attempted_at' => null, 'error' => null, 'updated_at' => now(),
            ]);
        }, 3);
    }

    /** @return list<array{purchase_id: int, grant_id: ?int, balance: int}> */
    private function availableBalances(int $workspaceId): array
    {
        $purchases = DB::table('billing_purchases')->where('workspace_id', $workspaceId)
            ->whereIn('status', ['paid', 'refund_pending', 'review', 'refunded'])->orderBy('id')->lockForUpdate()->get();
        $balances = [];
        foreach ($purchases as $purchase) {
            $balance = (int) DB::table('credit_ledger')->where('purchase_id', $purchase->id)->lockForUpdate()->pluck('credits')->sum();
            $spendable = $purchase->status === 'paid' && (! $purchase->expires_at || now()->lt($purchase->expires_at));
            $balances[] = ['purchase_id' => (int) $purchase->id, 'grant_id' => null,
                'balance' => $spendable ? $balance : min(0, $balance)];
            foreach (DB::table('broadcast_credit_grants')->where('purchase_id', $purchase->id)->orderBy('id')->lockForUpdate()->get() as $grant) {
                $balance = (int) $grant->balance;
                $balances[] = ['purchase_id' => (int) $purchase->id, 'grant_id' => (int) $grant->id,
                    'balance' => $purchase->status === 'paid' ? $balance : min(0, $balance)];
            }
        }

        return $balances;
    }

    /**
     * @param  list<array{purchase_id: int, grant_id: ?int, balance: int}>  $balances
     * @return array{purchase_id: int, grant_id: ?int, balance: int}
     */
    private function takeCredit(array &$balances): array
    {
        foreach ($balances as $index => $source) {
            if ($source['balance'] > 0) {
                $balances[$index]['balance']--;
                if ($source['grant_id'] !== null) {
                    DB::table('broadcast_credit_grants')->where('id', $source['grant_id'])->decrement('balance');
                }

                return $source;
            }
        }

        throw new \LogicException('No credit source is available.');
    }

    public function reconcile(string $messageId, string $phoneNumberId): void
    {
        DB::transaction(function () use ($messageId, $phoneNumberId): void {
            $recipient = DB::table('broadcast_recipients')
                ->join('broadcasts', 'broadcasts.id', '=', 'broadcast_recipients.broadcast_id')
                ->where('broadcasts.phone_number_id', $phoneNumberId)
                ->where('broadcast_recipients.message_id', $messageId)
                ->select('broadcast_recipients.*', 'broadcasts.workspace_id')->lockForUpdate()->first();
            if (! $recipient) {
                return;
            }
            $statuses = DB::table('whatsapp_events')->where('phone_number_id', $phoneNumberId)
                ->where('message_id', $messageId)->where('type', 'status')->lockForUpdate()->pluck('status')->all();
            if (in_array('read', $statuses, true) || in_array('delivered', $statuses, true)) {
                if ($recipient->status === 'failed') {
                    DB::table('billing_purchases')->where('id', $recipient->purchase_id)->lockForUpdate()->first();
                    $prefix = 'broadcast:'.$recipient->id.':'.$recipient->cycle;
                    DB::table('credit_ledger')->insertOrIgnore([
                        'workspace_id' => $recipient->workspace_id, 'purchase_id' => $recipient->purchase_id,
                        'type' => 'broadcast_refund_reversal', 'idempotency_key' => $prefix.':refund_reversal', 'credits' => $recipient->credit_grant_id === null ? -1 : 0, 'created_at' => now(),
                    ]);
                    if (DB::table('credit_ledger')->where('idempotency_key', $prefix.':void')->lockForUpdate()->first()) {
                        DB::table('credit_ledger')->insertOrIgnore([
                            'workspace_id' => $recipient->workspace_id, 'purchase_id' => $recipient->purchase_id,
                            'type' => 'broadcast_void_reversal', 'idempotency_key' => $prefix.':void_reversal', 'credits' => 1, 'created_at' => now(),
                        ]);
                    }
                    $returnedGrantId = $recipient->credit_grant_id !== null
                        ? (DB::table('credit_ledger')->where('idempotency_key', $prefix.':grant_refund')->lockForUpdate()->first() ? $recipient->credit_grant_id : null)
                        : DB::table('broadcast_credit_grants')->where('source_recipient_id', $recipient->id)->where('source_cycle', $recipient->cycle)->lockForUpdate()->value('id');
                    if ($returnedGrantId !== null) {
                        DB::table('broadcast_credit_grants')->where('id', $returnedGrantId)->decrement('balance');
                        DB::table('credit_ledger')->insert([
                            'workspace_id' => $recipient->workspace_id, 'purchase_id' => $recipient->purchase_id,
                            'type' => 'broadcast_grant_reversal', 'idempotency_key' => $prefix.':grant_reversal', 'credits' => 0, 'created_at' => now(),
                        ]);
                    }
                }
                DB::table('broadcast_recipients')->where('id', $recipient->id)->update([
                    'status' => in_array('read', $statuses, true) ? 'read' : 'delivered', 'error' => null, 'updated_at' => now(),
                ]);
            } elseif (in_array('failed', $statuses, true)) {
                $this->fail($recipient->id, 'Meta reported delivery failure.', $recipient->cycle, 'sent');
            }
        }, 3);
    }
}

<?php

namespace App\Support;

use App\Models\WhatsAppAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WhatsAppTemplateClient
{
    public function request(WhatsAppAccount $account, string $method, array $data = []): Response
    {
        return Http::withToken($account->access_token)->connectTimeout(5)->timeout(20)
            ->$method("https://graph.facebook.com/{$account->api_version}/{$account->business_account_id}/message_templates", $data);
    }

    public function all(WhatsAppAccount $account): array
    {
        $items = [];
        $cursor = null;
        $seen = [];
        for ($page = 0; $page < 100; $page++) {
            $query = ['fields' => 'id,name,language,category,status,components,rejected_reason', 'limit' => 100];
            if ($cursor !== null) {
                $query['after'] = $cursor;
            }
            try {
                $response = $this->request($account, 'get', $query);
            } catch (ConnectionException $exception) {
                Log::warning('Template synchronization could not reach Meta.');
                throw ValidationException::withMessages(['meta' => 'templates.unreachable']);
            }
            if (! $response->successful() || ! is_array($response->json('data'))) {
                Log::warning('Template synchronization failed.', ['status' => $response->status()]);
                throw ValidationException::withMessages(['meta' => 'templates.sync_failed']);
            }
            foreach ($response->json('data') as $item) {
                if (! is_array($item) || ! isset($item['id'], $item['name'], $item['language'], $item['category'], $item['status'], $item['components'])
                    || ! preg_match('/^[0-9]{1,64}$/', (string) $item['id']) || ! is_string($item['name']) || mb_strlen($item['name']) > 128
                    || ! is_string($item['language']) || mb_strlen($item['language']) > 32 || ! is_string($item['category']) || mb_strlen($item['category']) > 32
                    || ! is_string($item['status']) || mb_strlen($item['status']) > 64 || ! is_array($item['components'])) {
                    Log::warning('Meta returned an invalid template.');
                    throw ValidationException::withMessages(['meta' => 'templates.sync_failed']);
                }
                $items[] = $item;
            }
            if (! $response->json('paging.next')) {
                return $items;
            }
            $cursor = $response->json('paging.cursors.after');
            if (! is_string($cursor) || $cursor === '' || isset($seen[$cursor])) {
                break;
            }
            $seen[$cursor] = true;
        }
        Log::warning('Meta template pagination was incomplete.');
        throw ValidationException::withMessages(['meta' => 'templates.sync_failed']);
    }
}

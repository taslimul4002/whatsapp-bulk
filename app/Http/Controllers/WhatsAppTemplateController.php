<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveWhatsAppTemplateRequest;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use App\Support\WhatsAppTemplateClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppTemplateController extends Controller
{
    private function account(): WhatsAppAccount
    {
        return WhatsAppAccount::find(1) ?? throw ValidationException::withMessages(['meta' => 'whatsapp.save_first']);
    }

    private function currentTemplate(WhatsAppTemplate $template, WhatsAppAccount $account): void
    {
        abort_unless($template->business_account_id === $account->business_account_id, 404);
    }

    public function index(): Response
    {
        $account = WhatsAppAccount::find(1);

        return Inertia::render('Templates', [
            'configured' => $account !== null,
            'templates' => $account ? WhatsAppTemplate::query()->where('business_account_id', $account->business_account_id)->orderBy('name')->orderBy('language')->get() : [],
        ]);
    }

    public function store(SaveWhatsAppTemplateRequest $request): RedirectResponse
    {
        $account = $this->account();
        $data = $request->validated();
        WhatsAppTemplate::create([
            'business_account_id' => $account->business_account_id,
            'name' => $data['name'], 'language' => $data['language'], 'category' => $data['category'],
            'components' => $request->components(),
        ]);

        return back()->with('success', 'flash.template_saved');
    }

    public function update(SaveWhatsAppTemplateRequest $request, WhatsAppTemplate $template): RedirectResponse
    {
        $account = $this->account();
        $this->currentTemplate($template, $account);
        $data = $request->validated();
        $updated = WhatsAppTemplate::query()->whereKey($template->id)->where('status', 'DRAFT')->update([
            'name' => $data['name'], 'language' => $data['language'], 'category' => $data['category'],
            'components' => json_encode($request->components(), JSON_THROW_ON_ERROR),
        ]);
        abort_unless($updated, 409);

        return back()->with('success', 'flash.template_saved');
    }

    public function destroy(WhatsAppTemplate $template): RedirectResponse
    {
        $this->currentTemplate($template, $this->account());
        $deleted = WhatsAppTemplate::query()->whereKey($template->id)->where('status', 'DRAFT')->delete();
        abort_unless($deleted, 409);

        return back()->with('success', 'flash.template_deleted');
    }

    public function submit(WhatsAppTemplate $template, WhatsAppTemplateClient $client): RedirectResponse
    {
        $account = $this->account();
        $this->currentTemplate($template, $account);
        $claimed = WhatsAppTemplate::query()->whereKey($template->id)->where('status', 'DRAFT')->update(['status' => 'SUBMITTING']);
        abort_unless($claimed, 409);
        $template->refresh();
        abort_unless($template->status === 'SUBMITTING', 409);
        try {
            $response = $client->request($account, 'post', $template->only('name', 'language', 'category', 'components'));
        } catch (ConnectionException $exception) {
            WhatsAppTemplate::query()->whereKey($template->id)->where('status', 'SUBMITTING')->update(['status' => 'SUBMISSION_UNKNOWN']);
            Log::warning('Template submission connection failed.', ['template_id' => $template->id]);
            throw ValidationException::withMessages(['meta' => 'templates.submit_unknown']);
        }
        $id = $response->json('id');
        $status = $response->json('status');
        if (! $response->successful() || ! is_scalar($id) || ! preg_match('/^[0-9]{1,64}$/', (string) $id) || ! is_string($status) || $status === '' || mb_strlen($status) > 64) {
            $uncertain = $response->successful() || $response->serverError() || in_array($response->status(), [408, 409, 429], true);
            WhatsAppTemplate::query()->whereKey($template->id)->where('status', 'SUBMITTING')->update(['status' => $uncertain ? 'SUBMISSION_UNKNOWN' : 'DRAFT']);
            Log::warning('Template submission failed.', ['template_id' => $template->id, 'status' => $response->status(), 'error_code' => $response->json('error.code')]);
            $message = $response->json('error.error_user_msg');
            throw ValidationException::withMessages(['meta' => $uncertain ? 'templates.submit_unknown' : (is_string($message) && $message !== '' ? mb_substr($message, 0, 1000) : 'templates.submit_failed')]);
        }
        WhatsAppTemplate::query()->whereKey($template->id)->where('status', 'SUBMITTING')->update(['meta_id' => (string) $id, 'status' => $status]);

        return back()->with('success', 'flash.template_submitted');
    }

    public function recover(WhatsAppTemplate $template): RedirectResponse
    {
        $this->currentTemplate($template, $this->account());
        $updated = WhatsAppTemplate::query()->whereKey($template->id)->whereNull('meta_id')
            ->where(function ($query): void {
                $query->where('status', 'SUBMISSION_UNKNOWN')
                    ->orWhere(fn ($query) => $query->where('status', 'SUBMITTING')->where('updated_at', '<', now()->subMinutes(5)));
            })->update(['status' => 'DRAFT']);
        if (! $updated) {
            throw ValidationException::withMessages(['meta' => 'templates.recover_unavailable']);
        }

        return back()->with('success', 'flash.template_recovered');
    }

    public function sync(WhatsAppTemplateClient $client): RedirectResponse
    {
        $account = $this->account();
        $started = now()->timestamp;
        $items = $client->all($account);
        DB::transaction(function () use ($items, $account, $started): void {
            foreach ($items as $item) {
                $record = WhatsAppTemplate::query()->where('business_account_id', $account->business_account_id)
                    ->where(function ($query) use ($item): void {
                        $query->where('meta_id', (string) $item['id'])
                            ->orWhere(fn ($query) => $query->where('name', $item['name'])->where('language', $item['language']));
                    })->lockForUpdate()->first();
                $record ??= new WhatsAppTemplate(['business_account_id' => $account->business_account_id]);
                $record->fill([
                    'meta_id' => (string) $item['id'], 'name' => $item['name'], 'language' => $item['language'],
                    'category' => $item['category'], 'components' => $item['components'],
                ]);
                if (! $record->status_event_time || $record->status_event_time <= $started) {
                    $record->status = $item['status'];
                    $record->reason = is_string($item['rejected_reason'] ?? null) ? $item['rejected_reason'] : null;
                    $record->status_event_time = $started;
                }
                $record->save();
            }
        });

        return back()->with('success', 'flash.templates_synced');
    }
}

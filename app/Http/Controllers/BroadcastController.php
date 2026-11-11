<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use App\Models\Workspace;
use App\Support\BroadcastService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BroadcastController extends Controller
{
    public function index(Request $request, ?Workspace $workspace = null): Response
    {
        $workspaces = $request->user()->isAdmin() ? Workspace::query() : $request->user()->workspaces();
        $account = WhatsAppAccount::find(1);

        return Inertia::render('Broadcasts', [
            'workspaces' => $workspaces->orderBy('name')->get(['workspaces.id', 'workspaces.name']),
            'workspace' => $workspace?->only('id', 'name'),
            'groups' => $workspace?->contactGroups()->orderBy('name')->get(['id', 'name']) ?? [],
            'tags' => $workspace?->contactTags()->orderBy('name')->get(['id', 'name']) ?? [],
            'eligibleContacts' => $workspace ? $workspace->contacts()->where('opted_in', true)
                ->with(['groups:id', 'tags:id'])->get(['id']) : [],
            'templates' => $account ? WhatsAppTemplate::query()->where('business_account_id', $account->business_account_id)
                ->where('status', 'APPROVED')->orderBy('name')->get(['id', 'name', 'language', 'components']) : [],
            'broadcasts' => $workspace ? DB::table('broadcasts')->where('workspace_id', $workspace->id)->orderByDesc('id')->paginate(50)->withQueryString() : null,
        ]);
    }

    public function store(Request $request, Workspace $workspace, BroadcastService $service): RedirectResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'template_id' => ['required', 'integer'],
            'audience' => ['required', Rule::in(['all', 'groups', 'tags'])],
            'classification_ids' => ['present', 'array', 'max:100'],
            'classification_ids.*' => ['integer', 'distinct'],
            'mapping' => ['present', 'array', 'max:40'],
            'mapping.*' => ['required', Rule::in(['name', 'phone', 'email'])],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);
        if ($data['audience'] !== 'all') {
            $table = $data['audience'] === 'groups' ? 'contact_groups' : 'contact_tags';
            $count = DB::table($table)->where('workspace_id', $workspace->id)->whereIn('id', $data['classification_ids'])->count();
            if ($count === 0 || $count !== count($data['classification_ids'])) {
                throw ValidationException::withMessages(['classification_ids' => 'Select valid groups or tags in this workspace.']);
            }
        }
        $id = $service->create($workspace, $request->user()->id, $data);

        return redirect()->route('broadcasts.show', [$workspace, $id]);
    }

    public function show(Workspace $workspace, int $broadcast): Response
    {
        $record = DB::table('broadcasts')->where('workspace_id', $workspace->id)->where('id', $broadcast)->first();
        abort_unless($record, 404);

        return Inertia::render('BroadcastReport', [
            'workspace' => $workspace->only('id', 'name'),
            'broadcast' => $record,
            'counts' => DB::table('broadcast_recipients')->where('broadcast_id', $broadcast)->selectRaw('status, count(*) as total')->groupBy('status')->get(),
            'recipients' => DB::table('broadcast_recipients')->where('broadcast_id', $broadcast)->orderBy('id')
                ->paginate(50, ['id', 'phone', 'status', 'attempts', 'error', 'message_id', 'updated_at'])->withQueryString(),
        ]);
    }

    public function retry(Workspace $workspace, int $broadcast, int $recipient, BroadcastService $service): RedirectResponse
    {
        abort_unless(DB::table('broadcast_recipients')->where('id', $recipient)->where('broadcast_id', $broadcast)->exists()
            && DB::table('broadcasts')->where('id', $broadcast)->where('workspace_id', $workspace->id)->exists(), 404);
        $service->retry($workspace, $recipient);

        return back();
    }

    public function resolve(Request $request, Workspace $workspace, int $broadcast, int $recipient, BroadcastService $service): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['sent', 'failed'])],
            'message_id' => ['nullable', 'required_if:outcome,sent', 'string', 'max:255', Rule::unique('broadcast_recipients', 'message_id')],
        ]);
        DB::transaction(function () use ($workspace, $broadcast, $recipient, $service, $data, $request): void {
            $record = DB::table('broadcast_recipients')->where('id', $recipient)->where('broadcast_id', $broadcast)->lockForUpdate()->first();
            $parent = DB::table('broadcasts')->where('id', $broadcast)->where('workspace_id', $workspace->id)->first();
            abort_unless($record && $parent, 404);
            if ($record->status !== 'review' || ! $record->attempted_at || now()->lt(Carbon::parse($record->attempted_at)->addMinutes(2))) {
                throw ValidationException::withMessages(['outcome' => 'Only review items at least two minutes old can be resolved.']);
            }
            DB::table('broadcast_recipients')->where('id', $recipient)->update([
                'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            if ($data['outcome'] === 'failed') {
                $service->fail($recipient, 'Manually confirmed as not sent.');
            } else {
                DB::table('broadcast_recipients')->where('id', $recipient)->update([
                    'status' => 'sent', 'message_id' => $data['message_id'], 'error' => null, 'updated_at' => now(),
                ]);
                $service->reconcile($data['message_id'], $parent->phone_number_id);
            }
        }, 3);

        return back();
    }
}

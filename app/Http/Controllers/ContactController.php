<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveContactRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaces = $request->user()->isAdmin() ? Workspace::query() : $request->user()->workspaces();

        return Inertia::render('Contacts', [
            'workspaces' => $workspaces->orderBy('name')->get(['workspaces.id', 'workspaces.name']),
            'workspace' => null, 'contacts' => [], 'groups' => [], 'tags' => [],
        ]);
    }

    public function show(Workspace $workspace, Request $request): Response
    {
        $workspaces = $request->user()->isAdmin() ? Workspace::query() : $request->user()->workspaces();

        return Inertia::render('Contacts', [
            'workspaces' => $workspaces->orderBy('name')->get(['workspaces.id', 'workspaces.name']),
            'workspace' => $workspace->only('id', 'name'),
            'contacts' => $workspace->contacts()->with(['groups:id,name', 'tags:id,name'])->orderBy('name')->get(),
            'groups' => $workspace->contactGroups()->orderBy('name')->get(['id', 'name']),
            'tags' => $workspace->contactTags()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(SaveContactRequest $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($workspace, $data): void {
            $contact = $workspace->contacts()->create($data);
            $contact->groups()->sync($data['groups']);
            $contact->tags()->sync($data['tags']);
        });

        return back()->with('success', 'flash.contact_saved');
    }

    public function update(SaveContactRequest $request, Workspace $workspace, int $contact): RedirectResponse
    {
        $record = $workspace->contacts()->findOrFail($contact);
        $data = $request->validated();
        DB::transaction(function () use ($record, $data): void {
            $record->update($data);
            $record->groups()->sync($data['groups']);
            $record->tags()->sync($data['tags']);
        });

        return back()->with('success', 'flash.contact_saved');
    }

    public function destroy(Workspace $workspace, int $contact): RedirectResponse
    {
        $workspace->contacts()->findOrFail($contact)->delete();

        return back()->with('success', 'flash.contact_deleted');
    }
}

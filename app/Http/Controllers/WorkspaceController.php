<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveWorkspaceRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        $query = $request->user()->isAdmin() ? Workspace::query() : $request->user()->workspaces();

        return Inertia::render('Workspaces', [
            'workspaces' => $query->orderBy('name')->get(['workspaces.id', 'workspaces.name']),
        ]);
    }

    public function store(SaveWorkspaceRequest $request): RedirectResponse
    {
        Workspace::create($request->validated());

        return back()->with('success', 'flash.workspace_created');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContactClassificationController extends Controller
{
    public function store(Request $request, Workspace $workspace, string $kind): RedirectResponse
    {
        $relation = $this->relation($workspace, $kind);
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique($relation->getRelated()->getTable())->where('workspace_id', $workspace->id)]]);
        $relation->create($data);

        return back()->with('success', 'flash.classification_saved');
    }

    public function update(Request $request, Workspace $workspace, string $kind, int $classification): RedirectResponse
    {
        $relation = $this->relation($workspace, $kind);
        $record = $relation->findOrFail($classification);
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique($relation->getRelated()->getTable())->where('workspace_id', $workspace->id)->ignore($record->id)]]);
        $record->update($data);

        return back()->with('success', 'flash.classification_saved');
    }

    public function destroy(Workspace $workspace, string $kind, int $classification): RedirectResponse
    {
        $this->relation($workspace, $kind)->findOrFail($classification)->delete();

        return back()->with('success', 'flash.classification_deleted');
    }

    private function relation(Workspace $workspace, string $kind): HasMany
    {
        return match ($kind) {
            'groups' => $workspace->contactGroups(),
            'tags' => $workspace->contactTags(),
            default => abort(404),
        };
    }
}

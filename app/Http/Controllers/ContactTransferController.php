<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\ContactSpreadsheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactTransferController extends Controller
{
    public function import(Request $request, Workspace $workspace, ContactSpreadsheet $spreadsheet): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:2048']]);
        $rows = $spreadsheet->read($request->file('file'));
        DB::transaction(function () use ($workspace, $rows): void {
            foreach ($rows as $row) {
                $existing = $workspace->contacts()->where('phone', $row['phone'])->first();
                if ($existing) {
                    $existing->update(['name' => $row['name'], 'email' => $row['email']]);
                } else {
                    $workspace->contacts()->create($row);
                }
            }
        });

        return back()->with('success', 'flash.contacts_imported');
    }

    public function export(Workspace $workspace, string $format, ContactSpreadsheet $spreadsheet): StreamedResponse
    {
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);

        return response()->streamDownload(
            fn () => $spreadsheet->write($workspace->contacts()->orderBy('id')->cursor(), $format),
            "contacts.{$format}",
            ['Content-Type' => $format === 'csv' ? 'text/csv; charset=utf-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}

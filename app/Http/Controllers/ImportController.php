<?php

namespace App\Http\Controllers;

use App\Services\LegacyImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImportController extends Controller
{
    public function create(): View
    {
        return view('import.create', ['maxKb' => (int) config('gscraper.import_max_kb')]);
    }

    public function store(Request $request, LegacyImporter $importer): RedirectResponse
    {
        $maxKb = (int) config('gscraper.import_max_kb');
        $request->validate([
            'leads_file' => ['required', 'file', 'extensions:json', 'mimetypes:application/json,text/plain', "max:{$maxKb}"],
            // Content is re-validated line by line by the importer; this only keeps binaries out.
            'raw_file' => ['nullable', 'file', 'extensions:jsonl,json,txt', 'mimetypes:application/json,text/plain,application/jsonl,application/x-jsonlines,application/x-ndjson', "max:{$maxKb}"],
        ], [
            'leads_file.extensions' => __('app.validation.leads_file'),
            'leads_file.mimetypes' => __('app.validation.leads_file'),
            'raw_file.extensions' => __('app.validation.raw_file'),
            'raw_file.mimetypes' => __('app.validation.raw_file'),
        ]);

        $run = $importer->import($request->user(), $request->file('leads_file'), $request->file('raw_file'));

        return redirect()->route('runs.show', $run)->with('status', __('app.flash.imported', ['leads' => $run->leads_count, 'unreachable' => $run->unreachable_count, 'inactive' => $run->inactive_count]));
    }
}

<x-layouts.app :title="__('app.import.title')">
    <div class="max-w-2xl">
        <h1 class="mb-1 text-xl font-bold">{{ __('app.import.heading') }}</h1>
        <p class="muted mb-5">{!! __('app.import.intro', ['folder' => '<code class="code" dir="ltr">GMSCraper/output/</code>']) !!}</p>

        <form method="POST" action="{{ route('import.store') }}" enctype="multipart/form-data" class="card grid gap-4 p-5">
            @csrf
            <label class="label"><span dir="ltr">leads.json</span> <span class="font-normal">{{ __('app.import.leads_file') }}</span>
                <input class="input" type="file" name="leads_file" accept=".json,application/json" required>
            </label>
            <label class="label"><span dir="ltr">raw-places.jsonl</span> <span class="font-normal">{{ __('app.import.raw_file') }}</span>
                <input class="input" type="file" name="raw_file" accept=".jsonl,.json,.txt">
            </label>
            <p class="muted text-xs">{{ __('app.import.limits', ['mb' => number_format($maxKb / 1024, 0), 'businesses' => number_format(\App\Services\LegacyImporter::MAX_BUSINESSES)]) }}</p>
            <div><button class="btn btn-primary">{{ __('app.import.submit') }}</button></div>
        </form>
    </div>
</x-layouts.app>

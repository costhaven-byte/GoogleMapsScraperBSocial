<?php

namespace App\Http\Controllers;

use App\Enums\Stage;
use App\Models\RunBusiness;
use App\Services\RunRows;
use App\Support\Csv;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every reachable lead across all runs: the web version of leads-db.jsonl.
 */
class LeadController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('leads.index', [
            'leads' => $this->query($filters)->with('run')->paginate(50)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->query($this->filters($request))
            ->lazy(200)
            ->map(fn (RunBusiness $b) => ['run_id' => $b->run_id] + RunRows::lead($b));

        return Csv::download('leads-'.now()->format('Y-m-d').'.csv', $rows);
    }

    private function filters(Request $request): array
    {
        return [
            'tier' => in_array($request->query('tier'), ['A', 'B', 'C'], true) ? $request->query('tier') : null,
            'priority' => in_array($request->query('priority'), ['Hot', 'Warm', 'Cold'], true) ? $request->query('priority') : null,
            'q' => Str::limit(trim((string) $request->query('q')), 100, ''),
            'search' => Str::limit(trim((string) $request->query('search')), 200, ''),
            // By default a business re-checked in a later run only shows its newest result.
            'all' => $request->boolean('all'),
        ];
    }

    private function query(array $f): Builder
    {
        return RunBusiness::query()
            ->where('stage', Stage::Lead->value)
            ->when(! $f['all'], fn ($q) => $q->whereIn('id', RunBusiness::query()->selectRaw('MAX(id)')->groupBy('place_key')))
            ->when($f['tier'], fn ($q) => $q->where('contact_tier', $f['tier']))
            ->when($f['priority'], fn ($q) => $q->where('priority', $f['priority']))
            ->when($f['search'] !== '', fn ($q) => $q->where('query', $f['search']))
            ->when($f['q'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', Like::contains($f['q']))
                ->orWhere('category', 'like', Like::contains($f['q']))
                ->orWhere('email', 'like', Like::contains($f['q']))
                ->orWhere('address', 'like', Like::contains($f['q']))))
            ->orderByDesc('score')
            ->orderByDesc('id');
    }
}

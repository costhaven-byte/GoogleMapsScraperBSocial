<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Enums\Stage;
use App\Http\Requests\StoreRunRequest;
use App\Models\Run;
use App\Models\RunBusiness;
use App\Services\RunQueue;
use App\Services\RunRows;
use App\Support\Csv;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RunController extends Controller
{
    private const TABS = ['leads' => Stage::Lead, 'unreachable' => Stage::Unreachable, 'inactive' => Stage::Inactive];

    private const SORTS = [
        'score' => ['score', 'desc'],
        'name' => ['name', 'asc'],
        'reviews' => ['review_count', 'desc'],
        'rating' => ['rating', 'desc'],
    ];

    public function __construct(private RunQueue $queue) {}

    public function index(Request $request): View
    {
        $status = RunStatus::tryFrom((string) $request->query('status'));

        return view('runs.index', [
            'runs' => Run::query()
                ->with('user')
                ->when($status, fn ($q) => $q->where('status', $status->value))
                ->latest('id')
                ->paginate(25)
                ->withQueryString(),
            'status' => $status,
        ]);
    }

    public function store(StoreRunRequest $request): RedirectResponse
    {
        $run = $this->queue->createScrape($request->user(), $request->searches(), $request->options());

        return redirect()->route('runs.show', $run)->with('status', __('app.flash.search_queued'));
    }

    public function show(Request $request, Run $run): View
    {
        Gate::authorize('view', $run);
        $run->load(['user', 'worker', 'sourceRun']);

        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'leads';
        $tier = in_array($request->query('tier'), ['A', 'B', 'C'], true) ? $request->query('tier') : null;
        $priority = in_array($request->query('priority'), ['Hot', 'Warm', 'Cold'], true) ? $request->query('priority') : null;
        $search = Str::limit(trim((string) $request->query('q')), 100, '');
        $sort = array_key_exists((string) $request->query('sort'), self::SORTS) ? (string) $request->query('sort') : 'score';
        [$column, $direction] = self::SORTS[$sort];

        $businesses = $run->businesses()
            ->where('stage', self::TABS[$tab]->value)
            ->when($tab === 'leads' && $tier, fn ($q) => $q->where('contact_tier', $tier))
            ->when($tab === 'leads' && $priority, fn ($q) => $q->where('priority', $priority))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', Like::contains($search))
                ->orWhere('category', 'like', Like::contains($search))
                ->orWhere('email', 'like', Like::contains($search))
                ->orWhere('website_status', 'like', Like::contains($search))))
            ->orderBy($column, $direction)
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        return view('runs.show', [
            'run' => $run,
            'businesses' => $businesses,
            'tab' => $tab,
            'filters' => compact('tier', 'priority', 'search', 'sort'),
            'logs' => $run->logs()->orderByDesc('id')->limit(300)->get()->reverse()->values(),
        ]);
    }

    /**
     * Polled by the run page every few seconds while a worker is on the job.
     */
    public function progress(Request $request, Run $run): JsonResponse
    {
        Gate::authorize('view', $run);
        $run->load('worker');

        $logs = $run->logs()
            ->where('id', '>', max(0, (int) $request->query('after', 0)))
            ->orderBy('id')
            ->limit(500)
            ->get();

        return response()->json([
            'status' => $run->status->value,
            'statusLabel' => $run->status->label(),
            'finished' => ! $run->isActive(),
            'cancelRequested' => $run->cancel_requested,
            'progress' => $run->progress,
            'workerOnline' => (bool) $run->worker?->isOnline(),
            'logs' => $logs->map(fn ($l) => [
                'id' => $l->id,
                'level' => $l->level,
                'message' => $l->message,
                'time' => local_time($l->created_at, 'g:i:s A'),
            ]),
        ]);
    }

    public function cancel(Run $run): RedirectResponse
    {
        Gate::authorize('cancel', $run);
        $this->queue->cancel($run);

        return back()->with('status', $run->status === RunStatus::Cancelled ? __('app.flash.run_cancelled') : __('app.flash.stop_requested'));
    }

    public function reprocess(Request $request, Run $run): RedirectResponse
    {
        Gate::authorize('reprocess', $run);
        $new = $this->queue->createReprocess($request->user(), $run);

        return redirect()->route('runs.show', $new)->with('status', __('app.flash.reprocess_queued'));
    }

    public function destroy(Run $run): RedirectResponse
    {
        Gate::authorize('delete', $run);
        $run->delete();

        return redirect()->route('runs.index')->with('status', __('app.flash.run_deleted'));
    }

    public function export(Run $run, string $type): StreamedResponse
    {
        Gate::authorize('view', $run);

        $rows = $run->businesses()
            ->where('stage', self::TABS[$type]->value)
            ->orderByDesc('score')
            ->orderBy('id')
            ->lazy(200)
            ->map(fn (RunBusiness $b) => match ($type) {
                'leads' => RunRows::lead($b),
                'unreachable' => RunRows::unreachable($b),
                'inactive' => RunRows::inactive($b),
            });

        return Csv::download("run-{$run->id}-{$type}.csv", $rows);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\RunStatus;
use App\Http\Controllers\Controller;
use App\Models\Run;
use App\Models\RunPlace;
use App\Models\Worker;
use App\Services\BusinessRecorder;
use App\Services\RunQueue;
use App\Services\ScrapeBudget;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

/**
 * HTTPS API for local workers. Every call is short and bounded in size, which
 * keeps it well inside shared-hosting execution and memory limits.
 */
class WorkerApiController extends Controller
{
    private const USAGE_KEYS = ['now', 'used', 'limit', 'remaining', 'perRun', 'runBudget', 'canStart', 'nextStartAt', 'reason', 'fullResetAt'];

    public function __construct(
        private RunQueue $queue,
        private BusinessRecorder $recorder,
        private ScrapeBudget $budget,
    ) {}

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:40'],
            'hostname' => ['nullable', 'string', 'max:100'],
            'usage' => ['nullable', 'array'],
        ]);
        $worker = $this->worker($request);
        $worker->forceFill([
            'version' => $data['version'] ?? $worker->version,
            'hostname' => $data['hostname'] ?? $worker->hostname,
            'usage' => isset($data['usage']) ? Arr::only($data['usage'], self::USAGE_KEYS) : $worker->usage,
        ])->save();

        return response()->json([
            'serverTime' => now()->getTimestampMs(),
            'pollSeconds' => (int) config('gscraper.worker_poll_seconds'),
            'connection' => $this->connectionPayload($worker),
        ]);
    }

    /**
     * Google rate-limits by IP, so the budget is shared by every worker on this
     * connection and enforced here rather than on any one PC.
     */
    private function connectionPayload(Worker $worker): array
    {
        $budget = $this->budget->forWorker($worker);

        return [
            'limit' => $budget['limit'],
            'used' => $budget['used'],
            'remaining' => $budget['remaining'],
            'runBudget' => $budget['runBudget'],
            'canScrape' => $budget['canScrape'],
            'workers' => $budget['workers'],
            'workerNames' => $budget['workerNames'],
            'nextSlotAt' => $budget['nextSlotAt']?->getTimestampMs(),
        ];
    }

    public function claim(Request $request): JsonResponse|Response
    {
        $data = $request->validate([
            'canScrape' => ['required', 'boolean'],
            'usage' => ['nullable', 'array'],
        ]);
        $worker = $this->worker($request);
        if (isset($data['usage'])) {
            $worker->forceFill(['usage' => Arr::only($data['usage'], self::USAGE_KEYS)])->save();
        }

        // A worker may only scrape if its own limits allow it AND this connection's
        // shared daily budget still has room. Re-process jobs are always allowed.
        $connection = $this->connectionPayload($worker);
        $run = $this->queue->claimNext($worker, (bool) $data['canScrape'] && $connection['canScrape']);
        if (! $run) {
            return response()->json(['job' => null, 'connection' => $connection]);
        }

        return response()->json([
            'job' => [
                'id' => $run->id,
                'type' => $run->type->value,
                'queries' => $run->queries,
                'options' => $run->options ?? [],
                'placesCount' => $run->places_count,
                'maxBusinesses' => $connection['runBudget'],
            ],
            'connection' => $connection,
        ]);
    }

    /** Saved places for a re-process job, paged by id. */
    public function places(Request $request, int $run): JsonResponse
    {
        $run = $this->ownedRun($request, $run);
        $after = max(0, (int) $request->query('after_id', 0));
        $limit = min(500, max(1, (int) $request->query('limit', 200)));

        $places = RunPlace::query()
            ->where('run_id', $run->id)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'query', 'data']);

        return response()->json([
            'places' => $places->map(fn (RunPlace $p) => ['id' => $p->id, 'query' => $p->query, 'place' => $p->data]),
            'nextAfterId' => $places->count() === $limit ? $places->last()->id : null,
        ]);
    }

    public function storePlaces(Request $request, int $run): JsonResponse
    {
        $run = $this->ownedRun($request, $run);
        $request->validate([
            'places' => ['required', 'array', 'min:1', 'max:100'],
            'places.*.query' => ['present', 'nullable', 'string', 'max:255'],
            'places.*.place' => ['required', 'array'],
            'places.*.place.name' => ['required', 'string', 'max:500'],
        ]);

        $stored = $this->recorder->recordPlaces($run, $request->input('places'));
        $run->forceFill(['places_count' => RunPlace::query()->where('run_id', $run->id)->count()]);
        $this->queue->touch($run);

        return response()->json(['stored' => $stored]);
    }

    public function logs(Request $request, int $run): JsonResponse
    {
        $run = $this->ownedRun($request, $run);
        $request->validate([
            'lines' => ['present', 'array', 'max:500'],
            'lines.*.message' => ['required', 'string', 'max:5000'],
            'lines.*.level' => ['nullable', 'in:info,warn,error'],
            'progress' => ['nullable', 'array'],
        ]);

        $progress = $request->input('progress');
        if (is_array($progress) && strlen((string) json_encode($progress)) > 16_000) {
            $progress = null;
        }
        $this->queue->appendLogs($run, $request->input('lines', []), $progress);

        return response()->json(['cancelRequested' => $run->cancel_requested]);
    }

    public function results(Request $request, int $run): JsonResponse
    {
        $run = $this->ownedRun($request, $run);
        $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.stage' => ['required', 'in:lead,unreachable,inactive'],
            'items.*.query' => ['present', 'nullable', 'string', 'max:255'],
            'items.*.place' => ['required', 'array'],
            'items.*.place.name' => ['required', 'string', 'max:500'],
            'items.*.activity' => ['nullable', 'array'],
            'items.*.contact' => ['nullable', 'array'],
            'items.*.channels' => ['nullable', 'array'],
            'items.*.scoring' => ['nullable', 'array'],
        ]);

        try {
            $stored = $this->recorder->recordBusinesses($run, $request->input('items'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $this->queue->touch($run);

        return response()->json(['stored' => $stored]);
    }

    public function complete(Request $request, int $run): JsonResponse
    {
        $run = $this->ownedRun($request, $run);
        $data = $request->validate([
            'status' => ['required', 'in:completed,failed,cancelled'],
            'error' => ['nullable', 'string', 'max:5000'],
            'meta' => ['nullable', 'array'],
        ]);

        $meta = Arr::only($data['meta'] ?? [], ['enrichmentStats', 'blocked', 'limitHit', 'maxReviewAgeMonths', 'generatedAt', 'workerVersion']);
        $this->queue->complete($run, RunStatus::from($data['status']), $data['error'] ?? null, $meta);

        return response()->json(['ok' => true]);
    }

    private function worker(Request $request): Worker
    {
        return $request->attributes->get('worker');
    }

    /**
     * The run must be claimed by this worker and still in progress. A 409 tells the
     * worker to abandon the job (e.g. it was marked failed after a long disconnect).
     */
    private function ownedRun(Request $request, int $id): Run
    {
        $run = Run::query()->whereKey($id)->where('worker_id', $this->worker($request)->id)->first();
        if (! $run) {
            throw new HttpResponseException(response()->json(['error' => 'Job not found for this worker.'], 404));
        }
        if (! in_array($run->status, RunStatus::inProgress(), true)) {
            throw new HttpResponseException(response()->json(['error' => 'This job is no longer active.', 'status' => $run->status->value], 409));
        }

        return $run;
    }
}

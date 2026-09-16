<?php

namespace App\Services;

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\RunLog;
use App\Models\RunPlace;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The job queue between the web app and local workers. Workers poll over HTTPS,
 * so nothing here needs a long-running server process.
 */
class RunQueue
{
    public function __construct(private BusinessRecorder $recorder) {}

    /**
     * @param  string[]  $queries
     * @param  array{limit: int, months: int, noSocials: bool, allowUnverified: bool}  $options
     */
    public function createScrape(User $user, array $queries, array $options): Run
    {
        $run = new Run;
        $run->forceFill([
            'user_id' => $user->id,
            'type' => RunType::Scrape,
            'status' => RunStatus::Queued,
            'queries' => array_values($queries),
            'options' => $options,
        ])->save();

        return $run;
    }

    /**
     * Queues a new run over the source run's saved places (copied server-side).
     */
    public function createReprocess(User $user, Run $source): Run
    {
        return DB::transaction(function () use ($user, $source) {
            $run = new Run;
            $run->forceFill([
                'user_id' => $user->id,
                'source_run_id' => $source->id,
                'type' => RunType::Reprocess,
                'status' => RunStatus::Queued,
                'queries' => $source->queries,
                'options' => $source->options ?? ['months' => (int) data_get($source->meta, 'maxReviewAgeMonths', 11)],
            ])->save();

            RunPlace::query()->insertUsing(
                ['run_id', 'query', 'place_key', 'name', 'data', 'created_at'],
                RunPlace::query()
                    ->where('run_id', $source->id)
                    ->selectRaw('?, query, place_key, name, data, ?', [$run->id, now()->toDateTimeString()])
            );
            $run->forceFill(['places_count' => $run->places()->count()])->save();

            return $run;
        });
    }

    /**
     * Atomically hands the oldest queued job to a worker. The conditional UPDATE
     * guarantees two workers can never claim the same run.
     */
    public function claimNext(Worker $worker, bool $canScrape): ?Run
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = Run::query()
                ->where('status', RunStatus::Queued->value)
                ->when(! $canScrape, fn ($q) => $q->where('type', RunType::Reprocess->value))
                ->orderBy('id')
                ->value('id');
            if (! $candidate) {
                return null;
            }

            $claimed = Run::query()
                ->whereKey($candidate)
                ->where('status', RunStatus::Queued->value)
                ->update([
                    'status' => RunStatus::Claimed->value,
                    'worker_id' => $worker->id,
                    'claimed_at' => now(),
                    'last_activity_at' => now(),
                    'updated_at' => now(),
                ]);
            if ($claimed === 1) {
                return Run::query()->find($candidate);
            }
        }

        return null;
    }

    public function touch(Run $run): void
    {
        $changes = ['last_activity_at' => now()];
        if ($run->status === RunStatus::Claimed) {
            $changes['status'] = RunStatus::Running;
        }
        $run->forceFill($changes)->save();
    }

    /**
     * @param  array<int, array{message: string, level?: string}>  $lines
     */
    public function appendLogs(Run $run, array $lines, ?array $progress): void
    {
        $now = now()->toDateTimeString();
        $rows = [];
        foreach ($lines as $line) {
            $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $line['message']) ?? '';
            $rows[] = [
                'run_id' => $run->id,
                'level' => in_array($line['level'] ?? 'info', ['info', 'warn', 'error'], true) ? ($line['level'] ?? 'info') : 'info',
                'message' => Str::limit($message, 2000),
                'created_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            RunLog::query()->insert($chunk);
        }
        if ($progress !== null) {
            $run->forceFill(['progress' => $progress]);
        }
        $this->touch($run);
    }

    public function complete(Run $run, RunStatus $status, ?string $error, array $meta): void
    {
        DB::transaction(function () use ($run, $status, $error, $meta) {
            $run->forceFill([
                'status' => $status,
                'error' => $error ? Str::limit($error, 5000) : null,
                'finished_at' => now(),
                'last_activity_at' => now(),
                'cancel_requested' => false,
                'meta' => array_merge($run->meta ?? [], $meta),
            ])->save();
            $this->recorder->refreshCounts($run);
        });
    }

    /**
     * Queued runs are cancelled at once; running ones get a flag the worker picks up
     * on its next log flush (every few seconds).
     */
    public function cancel(Run $run): void
    {
        if ($run->status === RunStatus::Queued) {
            $run->forceFill(['status' => RunStatus::Cancelled, 'finished_at' => now()])->save();
        } elseif ($run->isActive()) {
            $run->forceFill(['cancel_requested' => true])->save();
        }
    }

    /**
     * Fails runs whose worker went silent (PC switched off, crash, lost connection).
     */
    public function failStale(): int
    {
        $cutoff = now()->subMinutes((int) config('gscraper.worker_stale_minutes'));
        $stale = Run::query()
            ->whereIn('status', array_map(fn ($s) => $s->value, RunStatus::inProgress()))
            ->where('last_activity_at', '<', $cutoff)
            ->get();

        foreach ($stale as $run) {
            $this->appendLogs($run, [[
                'level' => 'error',
                'message' => 'The worker stopped responding, so this run was marked failed. Saved places can still be re-processed.',
            ]], null);
            $this->complete($run, RunStatus::Failed, 'Worker stopped responding.', []);
        }

        return $stale->count();
    }
}

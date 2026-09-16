<?php

namespace App\Services;

use App\Enums\RunType;
use App\Models\Run;
use App\Models\RunPlace;
use App\Models\Worker;
use Illuminate\Support\Carbon;

/**
 * Google rate-limits by IP address, not by computer, so every worker behind the
 * same public IP shares one daily scraping budget. The server is the authority:
 * it counts the places actually uploaded from that connection in the last 24
 * hours (re-process runs don't count, because they never contact Google).
 */
class ScrapeBudget
{
    /**
     * @return array{ip: ?string, limit: int, used: int, remaining: int, runBudget: int,
     *                canScrape: bool, workers: int, workerNames: array<int, string>, nextSlotAt: ?Carbon}
     */
    public function forWorker(Worker $worker): array
    {
        return $this->forIp($worker->last_ip, $worker);
    }

    public function forIp(?string $ip, ?Worker $fallback = null): array
    {
        $limit = max(1, (int) config('gscraper.scrape.daily_per_connection'));
        $perRun = max(1, (int) config('gscraper.scrape.per_run'));
        $minRun = min(max(1, (int) config('gscraper.scrape.min_run')), $limit);

        $peers = $ip
            ? Worker::query()->where('last_ip', $ip)->orderBy('id')->get(['id', 'name'])
            : collect($fallback ? [$fallback] : []);

        $times = $peers->isEmpty() ? collect() : RunPlace::query()
            ->whereIn('run_id', Run::query()
                ->whereIn('worker_id', $peers->pluck('id'))
                ->where('type', RunType::Scrape->value)
                ->select('id'))
            ->where('created_at', '>=', now()->subDay())
            ->orderBy('created_at')
            ->pluck('created_at');

        $used = $times->count();
        $remaining = max(0, $limit - $used);
        $canScrape = $remaining >= $minRun;

        // Slots free up 24h after each place was scraped. Scraping resumes once
        // minRun of them are free again.
        $index = $used - ($limit - $minRun) - 1;
        $nextSlotAt = $canScrape || $index < 0 || ! isset($times[$index])
            ? null
            : $times[$index]->copy()->addDay();

        return [
            'ip' => $ip,
            'limit' => $limit,
            'used' => $used,
            'remaining' => $remaining,
            'runBudget' => min($remaining, $perRun),
            'canScrape' => $canScrape,
            'workers' => $peers->count(),
            'workerNames' => $peers->pluck('name')->all(),
            'nextSlotAt' => $nextSlotAt,
        ];
    }

    /** One entry per connection, for the dashboard. */
    public function byConnection(iterable $workers): array
    {
        $out = [];
        foreach ($workers as $worker) {
            $ip = $worker->last_ip;
            $key = $ip ?? "worker:{$worker->id}";
            $out[$key] ??= $this->forWorker($worker);
        }

        return $out;
    }
}

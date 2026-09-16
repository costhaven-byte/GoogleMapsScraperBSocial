<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\Worker;
use App\Services\ScrapeBudget;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(ScrapeBudget $budget): View
    {
        $workers = Worker::query()->active()->orderByDesc('last_seen_at')->get();
        $online = $workers->first(fn (Worker $w) => $w->isOnline());

        return view('dashboard', [
            'workers' => $workers,
            'onlineWorker' => $online,
            // One shared daily budget per internet connection (Google limits by IP).
            'connections' => $budget->byConnection($workers),
            // Cool-downs and Google blocks are tracked by the worker itself.
            'workerStatus' => ($online ?? $workers->first())?->usage,
            'recentRuns' => Run::query()->with('user')->latest('id')->limit(8)->get(),
            'queuedCount' => Run::query()->where('status', RunStatus::Queued->value)->count(),
            'maxQueries' => (int) config('gscraper.max_queries_per_run'),
            'maxResults' => (int) config('gscraper.max_results_per_query'),
            'phoneIsChannel' => (bool) config('gscraper.contact.phone_is_channel'),
        ]);
    }
}

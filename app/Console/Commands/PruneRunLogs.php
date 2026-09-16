<?php

namespace App\Console\Commands;

use App\Models\RunLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('runs:prune-logs')]
#[Description('Delete run log lines older than RUN_LOG_RETENTION_DAYS')]
class PruneRunLogs extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('gscraper.log_retention_days'));
        $total = 0;
        // Small batches keep each DELETE short on shared MySQL.
        do {
            $ids = RunLog::query()->where('created_at', '<', $cutoff)->orderBy('id')->limit(2000)->pluck('id');
            $total += $ids->isEmpty() ? 0 : RunLog::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === 2000);

        $this->info("Deleted {$total} old log line(s).");

        return self::SUCCESS;
    }
}

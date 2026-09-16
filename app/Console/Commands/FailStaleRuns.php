<?php

namespace App\Console\Commands;

use App\Services\RunQueue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('runs:fail-stale')]
#[Description('Mark runs failed when their worker stopped reporting')]
class FailStaleRuns extends Command
{
    public function handle(RunQueue $queue): int
    {
        $count = $queue->failStale();
        if ($count) {
            $this->info("Marked {$count} stale run(s) as failed.");
        }

        return self::SUCCESS;
    }
}

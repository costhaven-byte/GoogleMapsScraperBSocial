<?php

use Illuminate\Support\Facades\Schedule;

// Driven by one SiteGround cron job: * * * * * php artisan schedule:run
// Every task is short-lived; nothing needs a persistent process.

Schedule::command('runs:fail-stale')->everyMinute()->withoutOverlapping();
Schedule::command('runs:prune-logs')->dailyAt('03:15');
Schedule::command('auth:clear-resets')->dailyAt('03:30');

// Drains the database queue (e.g. password-reset mail if queued) and exits.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping();

<?php

// Application-specific settings. Every value can be overridden in .env.

return [

    // Languages the interface can be shown in. The switcher in the header offers
    // exactly these, and only these are accepted from the language cookie.
    'locales' => [
        'en' => ['native' => 'English', 'short' => 'EN', 'rtl' => false],
        'ar' => ['native' => 'العربية', 'short' => 'ع', 'rtl' => true],
    ],

    // Timezone used when showing dates (everything is stored in UTC).
    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'UTC'),

    // A worker counts as online if it contacted the server this recently.
    'worker_online_seconds' => (int) env('WORKER_ONLINE_SECONDS', 90),

    // How often an idle worker should poll for new jobs.
    'worker_poll_seconds' => (int) env('WORKER_POLL_SECONDS', 15),

    // A claimed/running run with no worker contact for this long is marked failed.
    'worker_stale_minutes' => (int) env('WORKER_STALE_MINUTES', 10),

    // Run log lines older than this are deleted by the scheduler (runs themselves are kept).
    'log_retention_days' => (int) env('RUN_LOG_RETENTION_DAYS', 30),

    // Outreach channels. Phone and SMS only count as a way to reach a lead when the
    // sender can actually call them (same country). WhatsApp always counts.
    // This is the default for the search form; each run stores its own choice.
    'contact' => [
        'phone_is_channel' => (bool) env('PHONE_IS_CHANNEL', false),
    ],

    // Shared scraping budget. Google rate-limits by IP address, so all workers behind
    // one public IP share these numbers; the server enforces them.
    'scrape' => [
        'daily_per_connection' => (int) env('SCRAPE_DAILY_PER_CONNECTION', 300),
        'per_run' => (int) env('SCRAPE_PER_RUN', 100),
        'min_run' => (int) env('SCRAPE_MIN_RUN', 10),
    ],

    // Limits on what one search request may ask for.
    'max_queries_per_run' => (int) env('MAX_QUERIES_PER_RUN', 50),
    'max_results_per_query' => (int) env('MAX_RESULTS_PER_QUERY', 200),

    // Legacy import upload limit in kilobytes.
    'import_max_kb' => (int) env('IMPORT_MAX_KB', 20480),

    // Behind SiteGround's proxy: comma-separated IPs, or * to trust the forwarding proxy.
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    // Force https:// in generated URLs (recommended in production).
    'force_https' => (bool) env('FORCE_HTTPS', false),

    // Send Strict-Transport-Security. Only enable once HTTPS works on the domain.
    'hsts' => (bool) env('SECURITY_HSTS', false),

    // Optional one-time admin bootstrap for `php artisan db:seed` (remove from .env afterwards).
    'initial_admin' => [
        'email' => env('INITIAL_ADMIN_EMAIL'),
        'password' => env('INITIAL_ADMIN_PASSWORD'),
    ],
];

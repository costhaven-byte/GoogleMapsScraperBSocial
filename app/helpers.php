<?php

use Carbon\CarbonInterface;

if (! function_exists('local_time')) {
    /**
     * Formats a stored (UTC) timestamp in the configured display timezone, with
     * day and month names in the interface language.
     */
    function local_time(?CarbonInterface $time, ?string $format = null): string
    {
        $format ??= __('app.common.datetime_format');

        return $time
            ? $time->copy()->setTimezone(config('gscraper.display_timezone'))->locale(app()->getLocale())->translatedFormat($format)
            : __('app.common.dash');
    }
}

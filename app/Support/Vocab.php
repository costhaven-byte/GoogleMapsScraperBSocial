<?php

namespace App\Support;

/**
 * Translates the fixed vocabularies the worker sends (scoring areas, suggested
 * packages, website status, reason codes) into the interface language.
 *
 * Anything unknown is returned unchanged, so a newer worker that adds a value
 * still displays something sensible instead of a missing-translation key.
 */
class Vocab
{
    /** Scoring area label, by the key the worker sends (e.g. "paidMedia"). */
    public static function area(array $area): string
    {
        $key = $area['key'] ?? null;

        return $key && trans()->has("data.area.$key")
            ? __("data.area.$key")
            : (string) ($area['label'] ?? $key ?? '');
    }

    /** One suggested package, by its exact English name. */
    public static function pitch(string $pitch): string
    {
        return trans()->has("data.pitch.$pitch") ? __("data.pitch.$pitch") : $pitch;
    }

    /**
     * Website status. Two of them carry a platform name in brackets
     * ("Social page only (Facebook)"), so those are matched on their prefix.
     */
    public static function websiteStatus(?string $status): string
    {
        $status = (string) $status;

        if ($status === '') {
            return '';
        }

        if (trans()->has("data.website_status.$status")) {
            return __("data.website_status.$status");
        }

        if (preg_match('/^(Social page only|Third-party page only) \((.+)\)$/', $status, $m)) {
            return __("data.website_status.{$m[1]}", ['platform' => $m[2]]);
        }

        return $status;
    }

    /** Human-readable meaning of a contactability reason code. */
    public static function reason(?string $code): string
    {
        $code = (string) $code;

        return $code !== '' && trans()->has("data.reason_code.$code") ? __("data.reason_code.$code") : $code;
    }

    /** Hot / Warm / Cold. */
    public static function priority(?string $priority): string
    {
        $priority = (string) $priority;

        return $priority !== '' && trans()->has("data.priority.$priority") ? __("data.priority.$priority") : $priority;
    }
}

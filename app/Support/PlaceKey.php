<?php

namespace App\Support;

final class PlaceKey
{
    /**
     * Google place keys are usually "0x...:0x..." but can fall back to a URL
     * fragment. Long ones are hashed so they fit the indexed 191-char column.
     */
    public static function normalize(?string $key, ?string $fallback = null): string
    {
        $key = trim((string) ($key ?: $fallback));
        if ($key === '') {
            $key = 'unknown-'.bin2hex(random_bytes(8));
        }

        return mb_strlen($key) > 191 ? 'sha1:'.sha1($key) : $key;
    }
}

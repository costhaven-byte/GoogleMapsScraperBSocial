<?php

namespace App\Support;

final class SafeUrl
{
    /**
     * Scraped URLs are untrusted. Only plain http(s) links may become an href:
     * anything else (javascript:, data:, vbscript:...) is dropped.
     */
    public static function http(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2048) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true) || ! parse_url($url, PHP_URL_HOST)) {
            return null;
        }

        return $url;
    }

    /** Host + path, for compact link text. */
    public static function short(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        return preg_replace('/^www\./', '', $host).$path;
    }
}

<?php

namespace App\Support;

final class Like
{
    /** Wraps user input for a LIKE '%...%' search with its wildcards escaped. */
    public static function contains(string $value): string
    {
        return '%'.addcslashes($value, '%_\\').'%';
    }
}

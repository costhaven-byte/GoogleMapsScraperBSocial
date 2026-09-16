<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Member = 'member';

    /** Translated for the interface language; see lang/<locale>/data.php. */
    public function label(): string
    {
        return (string) __('data.role.'.$this->value);
    }
}

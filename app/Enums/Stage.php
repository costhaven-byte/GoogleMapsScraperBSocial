<?php

namespace App\Enums;

enum Stage: string
{
    /** Reachable in writing (tier A-C) and scored. */
    case Lead = 'lead';

    /** Tier D: phone or address only. Never scored. */
    case Unreachable = 'unreachable';

    /** Dropped by the activity gate. */
    case Inactive = 'inactive';
}

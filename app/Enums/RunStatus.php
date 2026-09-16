<?php

namespace App\Enums;

enum RunStatus: string
{
    case Queued = 'queued';
    case Claimed = 'claimed';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** Statuses a worker is (or will soon be) working on. */
    public static function inProgress(): array
    {
        return [self::Claimed, self::Running];
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    /** Translated for the interface language; see lang/<locale>/data.php. */
    public function label(): string
    {
        return (string) __('data.run_status.'.$this->value);
    }
}

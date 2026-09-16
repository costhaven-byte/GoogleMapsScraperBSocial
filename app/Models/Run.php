<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Enums\RunType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Nothing is mass-assignable: runs are only created and changed by RunQueue,
// BusinessRecorder and LegacyImporter with explicit forceFill() calls.
class Run extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => RunType::class,
            'status' => RunStatus::class,
            'queries' => 'array',
            'options' => 'array',
            'progress' => 'array',
            'meta' => 'array',
            'cancel_requested' => 'boolean',
            'claimed_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function title(): string
    {
        return implode(' · ', $this->queries ?: [__('app.insights.no_query')]);
    }

    public function isActive(): bool
    {
        return ! $this->status->isFinished();
    }

    public function reachableTotal(): int
    {
        return $this->tier_a_count + $this->tier_b_count + $this->tier_c_count;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_run_id');
    }

    public function places(): HasMany
    {
        return $this->hasMany(RunPlace::class);
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(RunBusiness::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(RunLog::class);
    }
}

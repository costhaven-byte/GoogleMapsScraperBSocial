<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['name'])]
#[Hidden(['token_hash'])]
class Worker extends Model
{
    public const TOKEN_PREFIX = 'gsw_';

    protected function casts(): array
    {
        return [
            'usage' => 'array',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Creates a worker and returns it with its plain token (shown to the admin once).
     *
     * @return array{0: Worker, 1: string}
     */
    public static function issue(string $name, ?User $creator): array
    {
        $token = self::TOKEN_PREFIX.Str::random(48);
        $worker = new self(['name' => $name]);
        $worker->forceFill([
            'token_hash' => self::hashToken($token),
            'token_prefix' => substr($token, 0, 10),
            'created_by' => $creator?->id,
        ])->save();

        return [$worker, $token];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        if (! str_starts_with($token, self::TOKEN_PREFIX) || strlen($token) > 100) {
            return null;
        }

        return self::query()
            ->where('token_hash', self::hashToken($token))
            ->whereNull('revoked_at')
            ->first();
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isOnline(): bool
    {
        return ! $this->revoked_at
            && $this->last_seen_at
            && $this->last_seen_at->gt(now()->subSeconds((int) config('gscraper.worker_online_seconds')));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

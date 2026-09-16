<?php

namespace App\Models;

use App\Enums\Stage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunBusiness extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'stage' => Stage::class,
            'detail' => 'array',
            'pitch' => 'array',
            'rating' => 'float',
            'score' => 'integer',
            'review_count' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /** Value from the full pipeline record, e.g. d('channels.emails'). */
    public function d(string $path, mixed $default = null): mixed
    {
        return data_get($this->detail, $path, $default);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunPlace extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}

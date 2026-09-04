<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChangeSet extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'base_content_sha', 'state', 'summary', 'validation_report', 'published_sha'];

    protected function casts(): array
    {
        return ['validation_report' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(ChangeOperation::class);
    }

    public function publishRuns(): HasMany
    {
        return $this->hasMany(PublishRun::class);
    }
}

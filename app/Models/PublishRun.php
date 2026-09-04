<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishRun extends Model
{
    use HasUuids;

    protected $fillable = ['change_set_id', 'user_id', 'kind', 'state', 'stage', 'correlation_id', 'weblate_task_url', 'gitlab_pipeline_url', 'github_commit_url', 'metadata', 'error', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function changeSet(): BelongsTo
    {
        return $this->belongsTo(ChangeSet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

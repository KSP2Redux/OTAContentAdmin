<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChangeOperation extends Model
{
    use HasUuids;

    protected $fillable = ['change_set_id', 'channel', 'action', 'path', 'original_sha', 'payload_path', 'metadata', 'order_position'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function changeSet(): BelongsTo
    {
        return $this->belongsTo(ChangeSet::class);
    }
}

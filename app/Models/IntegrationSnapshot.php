<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationSnapshot extends Model
{
    protected $fillable = ['integration', 'status', 'data', 'checked_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'checked_at' => 'datetime'];
    }
}

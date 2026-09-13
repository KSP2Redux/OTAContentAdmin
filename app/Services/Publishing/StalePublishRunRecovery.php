<?php

namespace App\Services\Publishing;

use App\Models\PublishRun;
use Illuminate\Support\Facades\Cache;

final class StalePublishRunRecovery
{
    public function recover(): int
    {
        $runs = PublishRun::query()
            ->where('state', 'running')
            ->where(function ($query): void {
                $query
                    ->where(function ($query): void {
                        $query->where('kind', 'content')
                            ->where('stage', 'validate')
                            ->where('started_at', '<=', now()->subMinutes(5));
                    })
                    ->orWhere(function ($query): void {
                        $query->where('kind', 'content')
                            ->where('stage', 'publish_content')
                            ->where('started_at', '<=', now()->subMinutes(10));
                    })
                    ->orWhere('started_at', '<=', now()->subMinutes(45));
            })
            ->get();

        foreach ($runs as $run) {
            $owner = $run->metadata['lock_owner'] ?? null;
            if (is_string($owner) && $owner !== '') {
                Cache::restoreLock('ota-global-publish', $owner)->release();
            }

            $run->update([
                'state' => 'failed',
                'error' => 'Publication worker stopped unexpectedly. It is safe to retry.',
                'finished_at' => now(),
            ]);
            $run->changeSet()->whereNotIn('state', ['published'])->update(['state' => 'failed']);
        }

        return $runs->count();
    }
}

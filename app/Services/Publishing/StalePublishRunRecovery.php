<?php

namespace App\Services\Publishing;

use App\Models\PublishRun;
use Illuminate\Support\Facades\Cache;

final class StalePublishRunRecovery
{
    public function recover(): int
    {
        $runs = PublishRun::query()
            ->whereIn('state', ['queued', 'running', 'cancelling'])
            ->get();

        $recovered = 0;
        foreach ($runs as $run) {
            $inactiveSince = $run->updated_at ?? $run->started_at ?? $run->created_at;
            $stale = match ($run->state) {
                'queued' => $inactiveSince->lte(now()->subMinutes(5)),
                'cancelling' => $inactiveSince->lte(now()->subMinute()),
                default => $inactiveSince->lte(now()->subMinutes(2)),
            };
            if (! $stale) {
                continue;
            }

            $owner = $run->metadata['lock_owner'] ?? null;
            if (is_string($owner) && $owner !== '') {
                Cache::restoreLock('ota-global-publish', $owner)->release();
            }

            $cancelled = $run->state === 'cancelling';
            $run->update([
                'state' => $cancelled ? 'cancelled' : 'failed',
                'stage' => $cancelled ? 'cancelled' : $run->stage,
                'error' => $cancelled
                    ? 'Publication was stopped after its worker became unresponsive.'
                    : 'Publication worker stopped unexpectedly or stopped reporting progress. It is safe to retry.',
                'finished_at' => now(),
            ]);
            $cancelledState = $run->metadata['previous_change_set_state'] ?? 'validated';
            $run->changeSet()->whereNotIn('state', ['published'])->update(['state' => $cancelled ? $cancelledState : 'failed']);
            $recovered++;
        }

        return $recovered;
    }
}

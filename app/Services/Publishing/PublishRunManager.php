<?php

namespace App\Services\Publishing;

use App\Jobs\PublishChangeSet;
use App\Models\ChangeSet;
use App\Models\PublishRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class PublishRunManager
{
    public function queueChangeSet(ChangeSet $changeSet, string $userId): PublishRun
    {
        return DB::transaction(function () use ($changeSet, $userId): PublishRun {
            $lockedChangeSet = ChangeSet::query()->lockForUpdate()->findOrFail($changeSet->id);
            $alreadyActive = $lockedChangeSet->publishRuns()
                ->whereIn('state', ['queued', 'running', 'cancelling'])
                ->exists();

            if ($alreadyActive) {
                throw new RuntimeException('This change set already has an active publication.');
            }

            $run = PublishRun::create([
                'change_set_id' => $lockedChangeSet->id,
                'user_id' => $userId,
                'kind' => 'content',
                'state' => 'queued',
                'correlation_id' => (string) Str::uuid(),
                'metadata' => ['previous_change_set_state' => $lockedChangeSet->state],
            ]);
            $lockedChangeSet->update(['state' => 'queued']);
            PublishChangeSet::dispatch($lockedChangeSet->id, $run->id)->afterCommit();

            return $run;
        });
    }

    public function requestStop(PublishRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $lockedRun = PublishRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($lockedRun->state, ['queued', 'running', 'cancelling'], true)) {
                throw new RuntimeException('This publication is no longer active.');
            }

            $metadata = array_merge($lockedRun->metadata ?? [], ['cancel_requested_at' => now()->toIso8601String()]);
            if ($lockedRun->state === 'queued') {
                $lockedRun->update([
                    'state' => 'cancelled',
                    'stage' => 'cancelled',
                    'error' => 'Publication was stopped before it started.',
                    'metadata' => $metadata,
                    'finished_at' => now(),
                ]);
                $previousState = $lockedRun->metadata['previous_change_set_state'] ?? 'draft';
                $lockedRun->changeSet()->whereNotIn('state', ['published'])->update(['state' => $previousState]);

                return;
            }

            $lockedRun->update(['state' => 'cancelling', 'metadata' => $metadata]);
        });
    }
}

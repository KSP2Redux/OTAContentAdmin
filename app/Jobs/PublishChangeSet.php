<?php

namespace App\Jobs;

use App\Exceptions\PublicationCancelled;
use App\Models\ChangeSet;
use App\Models\PublishRun;
use App\Services\Content\ContentHandlerRegistry;
use App\Services\Content\Data\UploadedArtifact;
use App\Services\Integrations\WeblateClient;
use App\Services\Publishing\ChangeSetValidator;
use App\Services\Publishing\ContentPublisher;
use App\Services\Publishing\LocalizationPublisher;
use App\Services\Publishing\PayloadStorage;
use App\Support\SecretRedactor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class PublishChangeSet implements ShouldQueue
{
    use Queueable;

    public bool $failOnTimeout = true;

    public int $timeout = 3300;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $changeSetId, public string $publishRunId) {}

    /**
     * Execute the job.
     */
    public function handle(ContentPublisher $publisher, ChangeSetValidator $validator, LocalizationPublisher $localizations, ContentHandlerRegistry $handlers, WeblateClient $weblate, PayloadStorage $payloads): void
    {
        $run = PublishRun::findOrFail($this->publishRunId);
        $changeSet = ChangeSet::findOrFail($this->changeSetId);
        if ($run->state === 'cancelled') {
            return;
        }
        $lock = Cache::lock('ota-global-publish', 3600);
        try {
            if (! $lock->get()) {
                throw new \RuntimeException('Another OTA publication is active.');
            }
            $run->update([
                'state' => 'running',
                'stage' => 'validate',
                'started_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], ['lock_owner' => $lock->owner()]),
            ]);
            $checkpoint = fn (?string $stage = null) => $this->checkpoint($run, $stage);
            $checkpoint('validate');
            $validation = $validator->validate($changeSet, $checkpoint);
            if (! $validation['valid']) {
                throw new \RuntimeException(implode(' ', $validation['errors']));
            }
            $hasMissionSources = $changeSet->operations()->where('channel', 'missions')->get()->contains(fn ($operation) => ! empty($operation->metadata['localization_sources'] ?? []));
            if ($hasMissionSources) {
                foreach ($changeSet->operations()->where('channel', 'missions')->whereNotNull('payload_path')->get() as $operation) {
                    $sources = array_filter((array) ($operation->metadata['localization_sources'] ?? []));
                    $artifact = new UploadedArtifact($operation->path, $payloads->get($operation->payload_path), $operation->metadata ?? []);
                    $keys = $handlers->for('missions')->inspect($artifact)->metadata['localization_keys'] ?? [];
                    $missing = $weblate->missingMissionSourceKeys($keys);
                    $unresolved = array_diff($missing, array_keys($sources));
                    if ($unresolved) {
                        throw new \RuntimeException('Missing English source text for: '.implode(', ', $unresolved));
                    }
                    if ($missing) {
                        $weblate->addMissionSources(array_intersect_key($sources, array_flip($missing)));
                    }
                }
                $localizationRun = PublishRun::create(['change_set_id' => $changeSet->id, 'user_id' => $run->user_id, 'kind' => 'localization', 'state' => 'running', 'correlation_id' => (string) Str::uuid(), 'started_at' => now(), 'metadata' => ['parent_run_id' => $run->id]]);
                $checkpoint('publish_mission_localizations');
                $localizationCheckpoint = function () use ($checkpoint, $localizationRun): void {
                    $checkpoint();
                    $localizationRun->refresh();
                    $localizationRun->update(['metadata' => array_merge($localizationRun->metadata ?? [], ['heartbeat_at' => now()->toIso8601String()])]);
                };
                try {
                    $localizations->publish($localizationRun, $localizationCheckpoint);
                } catch (PublicationCancelled $exception) {
                    $localizationRun->update(['state' => 'cancelled', 'stage' => 'cancelled', 'error' => $exception->getMessage(), 'finished_at' => now()]);
                    throw $exception;
                } catch (Throwable $exception) {
                    $localizationRun->update(['state' => 'failed', 'error' => SecretRedactor::message($exception), 'finished_at' => now()]);
                    throw $exception;
                }
            }
            $checkpoint('publish_content');
            $sha = $publisher->publish($changeSet, 1, $checkpoint);
            $run->update(['state' => 'published', 'stage' => 'complete', 'github_commit_url' => 'https://github.com/'.config('ota.content.owner').'/'.config('ota.content.repo').'/commit/'.$sha, 'finished_at' => now()]);
        } catch (PublicationCancelled $exception) {
            if ($changeSet->state !== 'published') {
                $changeSet->update(['state' => $run->metadata['previous_change_set_state'] ?? 'validated']);
            }
            $run->update(['state' => 'cancelled', 'stage' => 'cancelled', 'error' => $exception->getMessage(), 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $state = str_contains($exception->getMessage(), 'changed since') || str_contains($exception->getMessage(), 'moved before') ? 'stale' : 'failed';
            if ($exception->getMessage() === 'Another OTA publication is active.') {
                $state = 'validated';
            }
            $changeSet->update(['state' => $state]);
            $run->update(['state' => 'failed', 'error' => SecretRedactor::message($exception), 'finished_at' => now()]);
            throw $exception;
        } finally {
            optional($lock)->release();
        }
    }

    private function checkpoint(PublishRun $run, ?string $stage = null): void
    {
        $run->refresh();
        if (in_array($run->state, ['cancelling', 'cancelled'], true)) {
            throw new PublicationCancelled;
        }

        $attributes = ['metadata' => array_merge($run->metadata ?? [], ['heartbeat_at' => now()->toIso8601String()])];
        if ($stage !== null) {
            $attributes['stage'] = $stage;
        }
        $run->update($attributes);
    }

    public function failed(?Throwable $exception): void
    {
        $run = PublishRun::find($this->publishRunId);
        if (! $run || in_array($run->state, ['published', 'failed', 'cancelled'], true)) {
            return;
        }

        $owner = $run->metadata['lock_owner'] ?? null;
        if (is_string($owner) && $owner !== '') {
            Cache::restoreLock('ota-global-publish', $owner)->release();
        }
        $run->update([
            'state' => 'failed',
            'error' => SecretRedactor::message($exception ?? new \RuntimeException('Publication worker stopped unexpectedly.')),
            'finished_at' => now(),
        ]);
        $run->changeSet()->whereNotIn('state', ['published'])->update(['state' => 'failed']);
    }
}

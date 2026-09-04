<?php

namespace App\Jobs;

use App\Models\ChangeSet;
use App\Models\PublishRun;
use App\Services\Content\ContentHandlerRegistry;
use App\Services\Content\Data\UploadedArtifact;
use App\Services\Integrations\WeblateClient;
use App\Services\Publishing\ChangeSetValidator;
use App\Services\Publishing\ContentPublisher;
use App\Services\Publishing\LocalizationPublisher;
use App\Support\SecretRedactor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PublishChangeSet implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $changeSetId, public string $publishRunId) {}

    /**
     * Execute the job.
     */
    public function handle(ContentPublisher $publisher, ChangeSetValidator $validator, LocalizationPublisher $localizations, ContentHandlerRegistry $handlers, WeblateClient $weblate): void
    {
        $run = PublishRun::findOrFail($this->publishRunId);
        $changeSet = ChangeSet::findOrFail($this->changeSetId);
        $lock = Cache::lock('ota-global-publish', 3600);
        try {
            if (! $lock->get()) {
                throw new \RuntimeException('Another OTA publication is active.');
            }
            $run->update(['state' => 'running', 'stage' => 'validate', 'started_at' => now()]);
            $validation = $validator->validate($changeSet);
            if (! $validation['valid']) {
                throw new \RuntimeException(implode(' ', $validation['errors']));
            }
            $hasMissionSources = $changeSet->operations()->where('channel', 'missions')->get()->contains(fn ($operation) => ! empty($operation->metadata['localization_sources'] ?? []));
            if ($hasMissionSources) {
                foreach ($changeSet->operations()->where('channel', 'missions')->whereNotNull('payload_path')->get() as $operation) {
                    $sources = array_filter((array) ($operation->metadata['localization_sources'] ?? []));
                    $artifact = new UploadedArtifact($operation->path, Storage::disk('ota-private')->get($operation->payload_path), $operation->metadata ?? []);
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
                $localizationRun = PublishRun::create(['change_set_id' => $changeSet->id, 'user_id' => $run->user_id, 'kind' => 'localization', 'state' => 'running', 'correlation_id' => (string) Str::uuid(), 'started_at' => now()]);
                $run->update(['stage' => 'publish_mission_localizations']);
                $localizations->publish($localizationRun);
            }
            $sha = $publisher->publish($changeSet);
            $run->update(['state' => 'published', 'stage' => 'complete', 'github_commit_url' => 'https://github.com/'.config('ota.content.owner').'/'.config('ota.content.repo').'/commit/'.$sha, 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $changeSet->update(['state' => str_contains($exception->getMessage(), 'changed since') || str_contains($exception->getMessage(), 'moved before') ? 'stale' : 'failed']);
            $run->update(['state' => 'failed', 'error' => SecretRedactor::message($exception), 'finished_at' => now()]);
            throw $exception;
        } finally {
            optional($lock)->release();
        }
    }
}

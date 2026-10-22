<?php

namespace App\Services\Publishing;

use App\Models\PublishRun;
use App\Services\Integrations\GitHubContentRepository;
use App\Services\Integrations\OneDevClient;
use App\Services\Integrations\WeblateClient;
use RuntimeException;

final readonly class LocalizationPublisher
{
    public function __construct(private WeblateClient $weblate, private OneDevClient $onedev, private GitHubContentRepository $github) {}

    public function publish(PublishRun $run, ?callable $checkpoint = null): void
    {
        $checkpoint && $checkpoint();
        $before = $this->github->headSha();
        $run->update(['stage' => 'weblate_commit']);
        $commitTask = $this->weblate->operation('commit');
        $run->update(['weblate_task_url' => config('ota.weblate.web_url').'/projects/'.config('ota.weblate.project').'/#repository']);
        $this->weblate->waitForTask($commitTask, $checkpoint);
        $run->update(['stage' => 'weblate_push']);
        $pushTask = $this->weblate->operation('push');
        $this->weblate->waitForTask($pushTask, $checkpoint);
        $repository = $this->weblate->status();
        $run->update(['stage' => 'onedev_build']);
        $build = $this->onedev->triggerOtaBuild();
        // Keep the existing database column so historical runs remain readable
        // and the integration change requires no schema migration.
        $run->update(['gitlab_pipeline_url' => $build['web_url']]);
        $this->onedev->waitForBuild($build['id'], $build['commitHash'], $checkpoint);
        $run->update(['stage' => 'verify_content']);
        $manifest = $this->github->manifest('localizations');
        foreach ($manifest['files'] ?? [] as $entry) {
            $checkpoint && $checkpoint();
            $contents = $this->github->file('localizations', $entry['path']);
            if (! hash_equals($entry['sha256'], hash('sha256', $contents)) || (isset($entry['bytes']) && (int) $entry['bytes'] !== strlen($contents))) {
                throw new RuntimeException('Published localization failed manifest verification: '.$entry['path']);
            }
        }
        $after = $this->github->headSha();
        $run->update(['state' => 'published', 'stage' => 'complete', 'github_commit_url' => 'https://github.com/'.config('ota.content.owner').'/'.config('ota.content.repo')."/commit/{$after}", 'metadata' => array_merge($run->metadata ?? [], ['before_sha' => $before, 'after_sha' => $after, 'weblate_commit_sha' => $repository['remote_commit'] ?? $repository['local_commit'] ?? $repository['revision'] ?? null, 'no_op' => $before === $after]), 'finished_at' => now()]);
    }
}

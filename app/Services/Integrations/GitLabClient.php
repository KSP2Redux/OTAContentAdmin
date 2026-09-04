<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GitLabClient
{
    public function triggerOtaPipeline(): array
    {
        return $this->request()->post($this->url('/projects/'.rawurlencode(config('ota.gitlab.project_id')).'/pipeline'), [
            'ref' => config('ota.gitlab.ref'),
            'variables' => [['key' => 'PUBLISH_OTA_CONTENT', 'value' => 'true']],
        ])->throw()->json();
    }

    public function waitForPipeline(int $id): array
    {
        $deadline = time() + config('ota.gitlab.timeout_seconds');
        do {
            $pipeline = $this->request()->get($this->url('/projects/'.rawurlencode(config('ota.gitlab.project_id'))."/pipelines/{$id}"))->throw()->json();
            if (($pipeline['status'] ?? null) === 'success') {
                return $pipeline;
            }
            if (in_array($pipeline['status'] ?? null, ['failed', 'canceled', 'skipped'], true)) {
                throw new RuntimeException('GitLab OTA pipeline ended with status '.$pipeline['status'].'.');
            }
            sleep(10);
        } while (time() < $deadline);
        throw new RuntimeException('GitLab OTA pipeline timed out.');
    }

    public function latest(): array
    {
        return $this->request()->get($this->url('/projects/'.rawurlencode(config('ota.gitlab.project_id')).'/pipelines'), ['per_page' => 1])->throw()->json()[0] ?? [];
    }

    public function assertOnlyOtaJobRan(int $pipelineId): array
    {
        $jobs = $this->request()->get($this->url('/projects/'.rawurlencode(config('ota.gitlab.project_id'))."/pipelines/{$pipelineId}/jobs"), ['per_page' => 100])->throw()->json();
        $names = array_values(array_unique(array_column($jobs, 'name')));
        $expected = config('ota.gitlab.job');
        if ($names !== [$expected]) {
            throw new RuntimeException('GitLab OTA pipeline created unexpected jobs: '.implode(', ', $names));
        }
        if (($jobs[0]['status'] ?? null) !== 'success') {
            throw new RuntimeException("GitLab OTA job {$expected} did not succeed.");
        }

        return $jobs[0];
    }

    private function request(): PendingRequest
    {
        $token = config('ota.gitlab.token');
        if (! $token) {
            throw new RuntimeException('GitLab token is not configured.');
        }

        return Http::withHeaders(['PRIVATE-TOKEN' => $token])->acceptJson()->timeout(30)->retry(3, 500, throw: false);
    }

    private function url(string $path): string
    {
        return rtrim(config('ota.gitlab.url'), '/').$path;
    }
}

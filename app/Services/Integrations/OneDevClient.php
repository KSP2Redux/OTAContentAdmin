<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OneDevClient
{
    public function head(): array
    {
        $branch = $this->request()->get($this->url('/repositories/'.config('ota.onedev.project_id').'/branches/'.rawurlencode(config('ota.onedev.ref'))))->throw()->json();
        if (! preg_match('/^[0-9a-f]{40}$/', $branch['commitHash'] ?? '')) {
            throw new RuntimeException('OneDev did not return a valid publication commit.');
        }

        return $branch;
    }

    public function triggerOtaBuild(): array
    {
        $head = $this->head();
        // Do not retry submissions: an uncertain response must not resubmit a
        // completed publication. GET requests can be retried safely.
        $id = $this->request(retry: false)->post($this->url('/job-runs'), [
            '@type' => 'JobRunOnCommit',
            'projectId' => (int) config('ota.onedev.project_id'),
            'commitHash' => $head['commitHash'],
            'refName' => 'refs/heads/'.config('ota.onedev.ref'),
            'jobName' => config('ota.onedev.job'),
            'params' => ['publication-commit' => [$head['commitHash']]],
            'reason' => 'OTA Admin localization publication',
        ])->throw()->json();
        if (! is_int($id) || $id < 1) {
            throw new RuntimeException('OneDev did not return a publication build ID.');
        }

        return $this->build($id, $head['commitHash']);
    }

    public function waitForBuild(int $id, string $commit, ?callable $checkpoint = null): array
    {
        $deadline = time() + config('ota.onedev.timeout_seconds');
        do {
            $checkpoint && $checkpoint();
            $build = $this->build($id, $commit);
            if ($build['status'] === 'SUCCESSFUL') {
                return $build;
            }
            if (in_array($build['status'], ['FAILED', 'CANCELLED', 'TIMED_OUT'], true)) {
                throw new RuntimeException('OneDev OTA publication ended with status '.$build['status'].'.');
            }
            sleep(10);
        } while (time() < $deadline);
        throw new RuntimeException('OneDev OTA publication timed out.');
    }

    public function latest(): array
    {
        $project = $this->request()->get($this->url('/projects/'.config('ota.onedev.project_id')))->throw()->json();
        $quote = fn (string $value): string => '"'.addcslashes($value, '\\"').'"';
        $query = '"Project" is '.$quote($project['path']).' and "Job" is '.$quote(config('ota.onedev.job')).' and "Branch" is '.$quote(config('ota.onedev.ref'));
        $builds = $this->request()->get($this->url('/builds'), ['query' => $query, 'offset' => 0, 'count' => 1])->throw()->json();

        return isset($builds[0]) ? $this->validate($builds[0]) : [];
    }

    private function build(int $id, string $commit): array
    {
        $build = $this->validate($this->request()->get($this->url('/builds/'.$id))->throw()->json());
        if (($build['id'] ?? null) !== $id || ($build['commitHash'] ?? null) !== $commit) {
            throw new RuntimeException('OneDev returned a different publication build or commit.');
        }

        return $build;
    }

    private function validate(array $build): array
    {
        if (($build['projectId'] ?? null) !== (int) config('ota.onedev.project_id')
            || ($build['jobName'] ?? null) !== config('ota.onedev.job')
            || ($build['refName'] ?? null) !== 'refs/heads/'.config('ota.onedev.ref')
            || ! isset($build['number'], $build['status'])) {
            throw new RuntimeException('OneDev returned a build outside the configured OTA publication job.');
        }
        $build['web_url'] = rtrim(config('ota.onedev.web_url'), '/').'/~builds/'.$build['number'];

        return $build;
    }

    private function request(bool $retry = true): PendingRequest
    {
        $token = config('ota.onedev.token');
        if (! $token) {
            throw new RuntimeException('OneDev publication token is not configured.');
        }
        $request = Http::withToken($token)->acceptJson()->timeout(30)->withoutRedirecting();

        return $retry ? $request->retry(3, 500, throw: false) : $request;
    }

    private function url(string $path): string
    {
        return rtrim(config('ota.onedev.url'), '/').$path;
    }
}

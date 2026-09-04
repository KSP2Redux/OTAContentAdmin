<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class WeblateClient
{
    public function status(): array
    {
        return $this->request()->get($this->url('/projects/'.config('ota.weblate.project').'/repository/'))->throw()->json();
    }

    public function operation(string $operation): array
    {
        return $this->request()->post($this->url('/projects/'.config('ota.weblate.project').'/repository/'), ['operation' => $operation])->throw()->json();
    }

    public function waitForTask(array $task): array
    {
        $url = $task['url'] ?? $task['task_url'] ?? null;
        if (! $url) {
            return $task;
        }
        $deadline = time() + config('ota.weblate.timeout_seconds');
        do {
            $result = $this->request()->get($url)->throw()->json();
            if (in_array($result['status'] ?? null, ['success', 'completed'], true)) {
                return $result;
            }
            if (in_array($result['status'] ?? null, ['failed', 'error'], true)) {
                throw new RuntimeException('Weblate task failed.');
            }
            sleep(5);
        } while (time() < $deadline);
        throw new RuntimeException('Weblate task timed out.');
    }

    /** @param array<string,string> $sources */
    public function addMissionSources(array $sources): array
    {
        $component = str_replace('/', '%252F', config('ota.weblate.missions_component'));
        $project = config('ota.weblate.project');
        $created = 0;
        $existing = 0;
        $componentInfo = $this->request()->get($this->url("/components/{$project}/{$component}/"))->throw()->json();
        if (! ($componentInfo['manage_units'] ?? false)) {
            throw new RuntimeException('Weblate missions component does not allow source-unit management.');
        }
        $language = $componentInfo['source_language']['code'] ?? 'en';
        foreach ($sources as $key => $value) {
            $response = $this->request()->post($this->url("/translations/{$project}/{$component}/{$language}/units/"), ['key' => $key, 'value' => [$value]]);
            if ($response->successful()) {
                $created++;

                continue;
            }
            $body = strtolower($response->body());
            if ($response->status() === 400 && (str_contains($body, 'already exist') || str_contains($body, 'unique') || str_contains($body, 'duplicate'))) {
                $existing++;

                continue;
            }
            $response->throw();
        }

        return compact('created', 'existing');
    }

    /** @param string[] $keys @return string[] */
    public function missingMissionSourceKeys(array $keys): array
    {
        $component = str_replace('/', '%252F', config('ota.weblate.missions_component'));
        $project = config('ota.weblate.project');
        $componentInfo = $this->request()->get($this->url("/components/{$project}/{$component}/"))->throw()->json();
        $language = $componentInfo['source_language']['code'] ?? 'en';
        $missing = [];
        foreach (array_unique($keys) as $key) {
            $result = $this->request()->get($this->url("/translations/{$project}/{$component}/{$language}/units/"), ['q' => 'context:"'.$key.'"'])->throw()->json();
            $found = collect($result['results'] ?? [])->contains(fn (array $unit) => ($unit['context'] ?? null) === $key);
            if (! $found) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function request(): PendingRequest
    {
        $token = config('ota.weblate.token');
        if (! $token) {
            throw new RuntimeException('Weblate token is not configured.');
        }

        return Http::withHeaders(['Authorization' => 'Token '.$token])->acceptJson()->timeout(30)->retry(3, 500, throw: false);
    }

    private function url(string $path): string
    {
        return rtrim(config('ota.weblate.url'), '/').$path;
    }
}

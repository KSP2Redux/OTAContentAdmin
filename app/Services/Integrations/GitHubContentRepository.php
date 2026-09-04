<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final readonly class GitHubContentRepository
{
    public function __construct(private GitHubAppTokenProvider $tokens) {}

    public function headSha(bool $authenticated = false): string
    {
        $response = $this->request($authenticated)->get($this->api('/git/ref/heads/'.config('ota.content.branch')))->throw()->json();

        return $response['object']['sha'];
    }

    public function manifest(string $channel): array
    {
        $response = Http::acceptJson()->get(rtrim(config('ota.content.raw_url'), '/')."/{$channel}/manifest.json");
        if ($response->status() === 404) {
            return ['files' => []];
        }

        return $response->throw()->json();
    }

    public function file(string $channel, string $path): string
    {
        return Http::get(rtrim(config('ota.content.raw_url'), '/')."/{$channel}/{$path}")->throw()->body();
    }

    public function manifestAtRef(string $channel, string $ref): array
    {
        return json_decode($this->fileAtRef($channel, 'manifest.json', $ref), true, 128, JSON_THROW_ON_ERROR);
    }

    public function fileAtRef(string $channel, string $path, string $ref): string
    {
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', "{$channel}/{$path}")));
        $response = $this->request(true)->get($this->api('/contents/'.$encodedPath), ['ref' => $ref])->throw()->json();
        if (($response['encoding'] ?? null) !== 'base64') {
            throw new RuntimeException('GitHub returned an unsupported content encoding.');
        }
        $contents = base64_decode(str_replace("\n", '', $response['content'] ?? ''), true);
        if ($contents === false) {
            throw new RuntimeException('GitHub returned invalid base64 content.');
        }

        return $contents;
    }

    /** @param array<string, string|null> $files */
    public function commit(array $files, string $message, string $expectedHead): string
    {
        $request = $this->request(true);
        $currentHead = $this->headSha(true);
        if (! hash_equals($expectedHead, $currentHead)) {
            throw new RuntimeException('Content/main moved before publication. Revalidate the change set.');
        }
        $commit = $request->get($this->api("/git/commits/{$currentHead}"))->throw()->json();
        $tree = [];
        foreach ($files as $path => $contents) {
            if ($contents === null) {
                $tree[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null];

                continue;
            }
            $blob = $request->post($this->api('/git/blobs'), ['content' => base64_encode($contents), 'encoding' => 'base64'])->throw()->json();
            $tree[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $blob['sha']];
        }
        $newTree = $request->post($this->api('/git/trees'), ['base_tree' => $commit['tree']['sha'], 'tree' => $tree])->throw()->json();
        $newCommit = $request->post($this->api('/git/commits'), ['message' => $message, 'tree' => $newTree['sha'], 'parents' => [$currentHead]])->throw()->json();
        $request->patch($this->api('/git/refs/heads/'.config('ota.content.branch')), ['sha' => $newCommit['sha'], 'force' => false])->throw();

        return $newCommit['sha'];
    }

    private function request(bool $authenticated): PendingRequest
    {
        $request = Http::acceptJson()->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])->retry(3, 500, throw: false);

        return $authenticated ? $request->withToken($this->tokens->token()) : $request;
    }

    private function api(string $path): string
    {
        return 'https://api.github.com/repos/'.config('ota.content.owner').'/'.config('ota.content.repo').$path;
    }
}

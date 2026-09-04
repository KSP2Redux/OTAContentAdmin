<?php

namespace App\Services\Integrations;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GitHubAppTokenProvider
{
    public function token(): string
    {
        return Cache::remember('github-app-token', now()->addMinutes(50), function (): string {
            $appId = config('ota.github_app.id');
            $installationId = config('ota.github_app.installation_id');
            $privateKey = str_replace('\\n', "\n", (string) config('ota.github_app.private_key'));
            if (! $appId || ! $installationId || ! $privateKey) {
                throw new RuntimeException('GitHub App credentials are not configured.');
            }
            $jwt = JWT::encode(['iat' => now()->subMinute()->timestamp, 'exp' => now()->addMinutes(9)->timestamp, 'iss' => $appId], $privateKey, 'RS256');
            $response = Http::withToken($jwt)->acceptJson()->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->post("https://api.github.com/app/installations/{$installationId}/access_tokens")->throw()->json();

            return $response['token'] ?? throw new RuntimeException('GitHub did not return an installation token.');
        });
    }
}

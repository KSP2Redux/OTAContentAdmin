<?php

namespace Tests\Feature;

use App\Services\Integrations\GitHubContentRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubContentRepositoryTest extends TestCase
{
    public function test_live_content_reads_use_the_authenticated_contents_api(): void
    {
        Cache::put('github-app-token', 'test-token');
        Http::fake([
            'https://api.github.com/repos/KSP2Redux/Content/contents/main-menu-vessels/manifest.json?ref=main' => Http::response([
                'encoding' => 'base64',
                'content' => base64_encode('{"files":[]}'),
            ]),
            'https://api.github.com/repos/KSP2Redux/Content/contents/main-menu-vessels/test.json?ref=main' => Http::response([
                'encoding' => 'base64',
                'content' => base64_encode('{"Assembly":[]}'),
            ]),
        ]);

        $repository = app(GitHubContentRepository::class);

        $this->assertSame(['files' => []], $repository->manifest('main-menu-vessels'));
        $this->assertSame('{"Assembly":[]}', $repository->file('main-menu-vessels', 'test.json'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'raw.githubusercontent.com'));
    }
}

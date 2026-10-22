<?php

namespace Tests\Feature;

use App\Services\Integrations\OneDevClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OneDevClientTest extends TestCase
{
    private function build(array $overrides = []): array
    {
        return array_replace([
            'id' => 1246, 'number' => 1104, 'projectId' => 1017,
            'jobName' => 'publish-ota-content', 'refName' => 'refs/heads/develop',
            'commitHash' => str_repeat('a', 40), 'status' => 'SUCCESSFUL',
        ], $overrides);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['ota.onedev.token' => 'publication-test-token']);
        Http::preventStrayRequests();
    }

    public function test_submission_pins_the_branch_commit_and_runs_only_the_ota_job(): void
    {
        Http::fake([
            '*/repositories/1017/branches/develop' => Http::response(['commitHash' => str_repeat('a', 40)]),
            '*/job-runs' => Http::response('1246', 200, ['Content-Type' => 'application/json']),
            '*/builds/1246' => Http::response($this->build()),
        ]);

        $build = app(OneDevClient::class)->triggerOtaBuild();
        $this->assertStringEndsWith('/~builds/1104', $build['web_url']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer publication-test-token')
            && $request['@type'] === 'JobRunOnCommit'
            && $request['projectId'] === 1017
            && $request['jobName'] === 'publish-ota-content'
            && $request['refName'] === 'refs/heads/develop'
            && $request['commitHash'] === str_repeat('a', 40)
            && $request['params'] === ['publication-commit' => [str_repeat('a', 40)]]);
        Http::assertSentCount(3);
    }

    public function test_failed_submission_is_not_retried(): void
    {
        Http::fake([
            '*/repositories/1017/branches/develop' => Http::response(['commitHash' => str_repeat('a', 40)]),
            '*/job-runs' => Http::response('temporarily unavailable', 503),
        ]);
        try {
            app(OneDevClient::class)->triggerOtaBuild();
            $this->fail('Submission should fail.');
        } catch (RequestException) {
            Http::assertSentCount(2);
        }
    }

    public function test_successful_build_is_verified_against_the_pinned_commit(): void
    {
        Http::fake(['*/builds/1246' => Http::response($this->build())]);
        $checkpoints = 0;
        $result = app(OneDevClient::class)->waitForBuild(1246, str_repeat('a', 40), function () use (&$checkpoints) {
            $checkpoints++;
        });
        $this->assertSame('SUCCESSFUL', $result['status']);
        $this->assertSame(1, $checkpoints);
    }

    public function test_different_commit_cannot_be_treated_as_a_successful_publication(): void
    {
        Http::fake(['*/builds/1246' => Http::response($this->build(['commitHash' => str_repeat('b', 40)]))]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different publication build or commit');
        app(OneDevClient::class)->waitForBuild(1246, str_repeat('a', 40));
    }

    public function test_another_job_is_rejected(): void
    {
        Http::fake(['*/builds/1246' => Http::response($this->build(['jobName' => 'publish-release']))]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the configured OTA publication job');
        app(OneDevClient::class)->waitForBuild(1246, str_repeat('a', 40));
    }

    public function test_cancelled_build_does_not_continue_to_content_verification(): void
    {
        Http::fake(['*/builds/1246' => Http::response($this->build(['status' => 'CANCELLED']))]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('status CANCELLED');
        app(OneDevClient::class)->waitForBuild(1246, str_repeat('a', 40));
    }
}

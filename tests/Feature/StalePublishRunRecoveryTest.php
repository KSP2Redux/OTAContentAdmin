<?php

namespace Tests\Feature;

use App\Models\ChangeSet;
use App\Models\PublishRun;
use App\Models\User;
use App\Services\Publishing\StalePublishRunRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class StalePublishRunRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fails_a_stale_validation_run_and_releases_only_its_lock(): void
    {
        $user = User::factory()->create();
        $changeSet = ChangeSet::create(['user_id' => $user->id, 'summary' => 'Large vessel', 'state' => 'validated']);
        $lock = Cache::lock('ota-global-publish', 3600);
        $this->assertTrue($lock->get());
        $run = PublishRun::create([
            'change_set_id' => $changeSet->id,
            'user_id' => $user->id,
            'kind' => 'content',
            'state' => 'running',
            'stage' => 'validate',
            'correlation_id' => (string) Str::uuid(),
            'metadata' => ['lock_owner' => $lock->owner()],
            'started_at' => now()->subMinutes(6),
        ]);
        PublishRun::query()->whereKey($run->id)->update(['updated_at' => now()->subMinutes(6)]);

        $this->assertSame(1, app(StalePublishRunRecovery::class)->recover());
        $this->assertSame('failed', $run->fresh()->state);
        $this->assertSame('failed', $changeSet->fresh()->state);
        $this->assertTrue(Cache::lock('ota-global-publish', 60)->get());
    }

    public function test_it_leaves_a_recent_validation_run_alone(): void
    {
        $user = User::factory()->create();
        $changeSet = ChangeSet::create(['user_id' => $user->id, 'summary' => 'Recent vessel', 'state' => 'validated']);
        $run = PublishRun::create([
            'change_set_id' => $changeSet->id,
            'user_id' => $user->id,
            'kind' => 'content',
            'state' => 'running',
            'stage' => 'validate',
            'correlation_id' => (string) Str::uuid(),
            'started_at' => now()->subMinutes(2),
        ]);

        $this->assertSame(0, app(StalePublishRunRecovery::class)->recover());
        $this->assertSame('running', $run->fresh()->state);
    }

    public function test_it_cancels_an_unresponsive_stop_request_and_releases_its_lock(): void
    {
        $user = User::factory()->create();
        $changeSet = ChangeSet::create(['user_id' => $user->id, 'summary' => 'Stopped vessel', 'state' => 'publishing']);
        $lock = Cache::lock('ota-global-publish', 3600);
        $this->assertTrue($lock->get());
        $run = PublishRun::create([
            'change_set_id' => $changeSet->id,
            'user_id' => $user->id,
            'kind' => 'content',
            'state' => 'cancelling',
            'stage' => 'validate',
            'correlation_id' => (string) Str::uuid(),
            'metadata' => ['lock_owner' => $lock->owner()],
            'started_at' => now()->subMinutes(5),
        ]);
        PublishRun::query()->whereKey($run->id)->update(['updated_at' => now()->subMinutes(3)]);

        $this->assertSame(1, app(StalePublishRunRecovery::class)->recover());
        $this->assertSame('cancelled', $run->fresh()->state);
        $this->assertSame('validated', $changeSet->fresh()->state);
        $this->assertTrue(Cache::lock('ota-global-publish', 60)->get());
    }

    public function test_heartbeats_keep_an_old_run_active(): void
    {
        $user = User::factory()->create();
        $changeSet = ChangeSet::create(['user_id' => $user->id, 'summary' => 'Active pipeline', 'state' => 'publishing']);
        $run = PublishRun::create([
            'change_set_id' => $changeSet->id,
            'user_id' => $user->id,
            'kind' => 'localization',
            'state' => 'running',
            'stage' => 'gitlab_pipeline',
            'correlation_id' => (string) Str::uuid(),
            'started_at' => now()->subMinutes(20),
        ]);
        PublishRun::query()->whereKey($run->id)->update(['updated_at' => now()->subMinute()]);

        $this->assertSame(0, app(StalePublishRunRecovery::class)->recover());
        $this->assertSame('running', $run->fresh()->state);
    }
}

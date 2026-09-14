<?php

namespace Tests\Feature;

use App\Jobs\PublishChangeSet;
use App\Models\ChangeSet;
use App\Models\PublishRun;
use App\Models\User;
use App\Services\Publishing\PublishRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class PublishRunManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_change_set_cannot_be_queued_while_it_already_has_an_active_run(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $changeSet = ChangeSet::create(['user_id' => $user->id, 'summary' => 'Vessels', 'state' => 'validated']);
        $manager = app(PublishRunManager::class);

        $first = $manager->queueChangeSet($changeSet, $user->id);

        try {
            $manager->queueChangeSet($changeSet, $user->id);
            $this->fail('A second active run was queued.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already has an active publication', $exception->getMessage());
        }
        $this->assertSame(1, PublishRun::query()->count());
        Queue::assertPushed(PublishChangeSet::class, 1);
    }

    public function test_a_queued_run_can_be_cancelled_before_the_worker_starts(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $changeSet = ChangeSet::create(['user_id' => $user->id, 'summary' => 'Vessels', 'state' => 'validated']);
        $manager = app(PublishRunManager::class);
        $run = $manager->queueChangeSet($changeSet, $user->id);

        $manager->requestStop($run);

        $this->assertSame('cancelled', $run->fresh()->state);
        $this->assertSame('validated', $changeSet->fresh()->state);
        $this->assertNotNull($run->fresh()->metadata['cancel_requested_at'] ?? null);
    }

    public function test_cancelling_a_queued_draft_restores_its_draft_state(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $changeSet = ChangeSet::create(['user_id' => $user->id, 'summary' => 'Draft vessels', 'state' => 'draft']);
        $manager = app(PublishRunManager::class);
        $run = $manager->queueChangeSet($changeSet, $user->id);

        $manager->requestStop($run);

        $this->assertSame('cancelled', $run->fresh()->state);
        $this->assertSame('draft', $changeSet->fresh()->state);
    }

    public function test_a_running_run_moves_to_cancelling_until_the_worker_acknowledges_it(): void
    {
        $user = User::factory()->create();
        $changeSet = ChangeSet::create(['user_id' => $user->id, 'summary' => 'Vessels', 'state' => 'publishing']);
        $run = PublishRun::create([
            'change_set_id' => $changeSet->id,
            'user_id' => $user->id,
            'kind' => 'content',
            'state' => 'running',
            'correlation_id' => fake()->uuid(),
        ]);

        app(PublishRunManager::class)->requestStop($run);

        $this->assertSame('cancelling', $run->fresh()->state);
        $this->assertNull($run->fresh()->finished_at);
    }
}

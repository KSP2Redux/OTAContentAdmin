<?php

namespace App\Jobs;

use App\Exceptions\PublicationCancelled;
use App\Models\PublishRun;
use App\Services\Publishing\LocalizationPublisher;
use App\Support\SecretRedactor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class FlushLocalizations implements ShouldQueue
{
    use Queueable;

    public bool $failOnTimeout = true;

    public int $timeout = 3300;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $publishRunId) {}

    /**
     * Execute the job.
     */
    public function handle(LocalizationPublisher $publisher): void
    {
        $run = PublishRun::findOrFail($this->publishRunId);
        if ($run->state === 'cancelled') {
            return;
        }
        $lock = Cache::lock('ota-global-publish', 3600);
        try {
            if (! $lock->get()) {
                throw new \RuntimeException('Another OTA publication is active.');
            }
            $run->update(['state' => 'running', 'started_at' => now(), 'metadata' => array_merge($run->metadata ?? [], ['lock_owner' => $lock->owner()])]);
            $checkpoint = function () use ($run): void {
                $run->refresh();
                if (in_array($run->state, ['cancelling', 'cancelled'], true)) {
                    throw new PublicationCancelled;
                }
                $run->update(['metadata' => array_merge($run->metadata ?? [], ['heartbeat_at' => now()->toIso8601String()])]);
            };
            $checkpoint();
            $publisher->publish($run, $checkpoint);
        } catch (PublicationCancelled $exception) {
            $run->update(['state' => 'cancelled', 'stage' => 'cancelled', 'error' => $exception->getMessage(), 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $run->update(['state' => 'failed', 'error' => SecretRedactor::message($exception), 'finished_at' => now()]);
            throw $exception;
        } finally {
            optional($lock)->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = PublishRun::find($this->publishRunId);
        if (! $run || in_array($run->state, ['published', 'failed', 'cancelled'], true)) {
            return;
        }

        $owner = $run->metadata['lock_owner'] ?? null;
        if (is_string($owner) && $owner !== '') {
            Cache::restoreLock('ota-global-publish', $owner)->release();
        }
        $run->update([
            'state' => 'failed',
            'error' => SecretRedactor::message($exception ?? new \RuntimeException('Publication worker stopped unexpectedly.')),
            'finished_at' => now(),
        ]);
    }
}

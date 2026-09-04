<?php

namespace App\Jobs;

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
        $lock = Cache::lock('ota-global-publish', 3600);
        try {
            if (! $lock->get()) {
                throw new \RuntimeException('Another OTA publication is active.');
            }
            $run->update(['state' => 'running', 'started_at' => now()]);
            $publisher->publish($run);
        } catch (Throwable $exception) {
            $run->update(['state' => 'failed', 'error' => SecretRedactor::message($exception), 'finished_at' => now()]);
            throw $exception;
        } finally {
            optional($lock)->release();
        }
    }
}

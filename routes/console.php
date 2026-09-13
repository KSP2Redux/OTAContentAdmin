<?php

use App\Services\Publishing\PayloadStorage;
use App\Services\Publishing\StalePublishRunRecovery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    app(PayloadStorage::class)->pruneOlderThan(now()->subDays(30)->timestamp);
})->daily()->name('prune-ota-drafts')->withoutOverlapping();

Schedule::call(function (): void {
    app(StalePublishRunRecovery::class)->recover();
})->everyMinute()->name('recover-stale-ota-publications')->withoutOverlapping();

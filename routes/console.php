<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    foreach (Storage::disk('ota-private')->allFiles('drafts') as $file) {
        if (Storage::disk('ota-private')->lastModified($file) < now()->subDays(30)->timestamp) {
            Storage::disk('ota-private')->delete($file);
        }
    }
})->daily()->name('prune-ota-drafts')->withoutOverlapping();

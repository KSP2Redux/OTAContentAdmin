<?php

namespace App\Filament\Widgets;

use App\Models\ChangeSet;
use App\Models\PublishRun;
use App\Services\Integrations\GitHubContentRepository;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OtaStatusOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        try {
            $sha = substr(app(GitHubContentRepository::class)->headSha(true), 0, 12);
        } catch (\Throwable) {
            $sha = 'Unavailable';
        }

        return [
            Stat::make('Content/main', $sha),
            Stat::make('Draft changesets', ChangeSet::query()->whereIn('state', ['draft', 'validated'])->count()),
            Stat::make('Queued or active', PublishRun::query()->whereIn('state', ['queued', 'running'])->count()),
            Stat::make('Failed publications', PublishRun::query()->where('state', 'failed')->count())->color('danger'),
        ];
    }
}

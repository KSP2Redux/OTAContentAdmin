<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Localizations;
use App\Filament\Pages\Missions;
use App\Filament\Pages\Vessels;
use App\Services\Integrations\GitHubContentRepository;
use App\Services\Integrations\WeblateClient;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ContentChannelsOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Published content';

    protected ?string $description = 'Select a card to manage its OTA channel.';

    protected function getStats(): array
    {
        return [
            $this->channelStat(
                label: 'Localizations',
                channel: 'localizations',
                description: $this->localizationState(),
                icon: 'heroicon-o-language',
                url: Localizations::getUrl(),
            ),
            $this->channelStat(
                label: 'Main-menu vessels',
                channel: 'main-menu-vessels',
                description: 'Published vessels · loaded on startup',
                icon: 'heroicon-o-rocket-launch',
                url: Vessels::getUrl(),
            ),
            $this->channelStat(
                label: 'Missions',
                channel: 'missions',
                description: 'Published OTA missions · loaded with campaigns',
                icon: 'heroicon-o-clipboard-document-list',
                url: Missions::getUrl(),
            ),
        ];
    }

    private function channelStat(string $label, string $channel, string $description, string $icon, string $url): Stat
    {
        try {
            $count = count(app(GitHubContentRepository::class)->manifest($channel)['files'] ?? []);

            return Stat::make($label, number_format($count))
                ->description($description)
                ->descriptionIcon($icon)
                ->color('primary')
                ->url($url);
        } catch (\Throwable) {
            return Stat::make($label, 'Unavailable')
                ->description('Could not load the published manifest')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->url($url);
        }
    }

    private function localizationState(): string
    {
        try {
            $status = app(WeblateClient::class)->status();
            $flags = collect(['needs_commit', 'needs_push', 'needs_merge'])
                ->filter(fn (string $key): bool => array_key_exists($key, $status));

            if ($flags->contains(fn (string $key): bool => (bool) $status[$key])) {
                return 'Weblate has changes waiting to publish';
            }

            if ($flags->isNotEmpty()) {
                return 'Weblate repository is up to date';
            }

            return 'Weblate repository connected';
        } catch (\Throwable) {
            return 'Weblate status unavailable';
        }
    }
}

<?php

namespace App\Filament\Pages;

use App\Services\Content\CompatibilityCatalog;
use App\Services\Integrations\GitHubContentRepository;
use App\Services\Integrations\GitLabClient;
use App\Support\SecretRedactor;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;

class SystemStatus extends Page
{
    protected string $view = 'filament.pages.system-status';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh status')
                ->icon('heroicon-o-arrow-path')
                ->url(static::getUrl()),
        ];
    }

    public function getViewData(): array
    {
        $cards = [
            $this->check(
                title: 'Content repository',
                description: 'The live OTA content consumed by KSP2 Redux.',
                icon: 'heroicon-o-code-bracket-square',
                callback: function (): array {
                    $sha = app(GitHubContentRepository::class)->headSha();

                    return [
                        'status' => 'Connected',
                        'details' => [
                            ['label' => 'Branch', 'value' => config('ota.content.branch')],
                            ['label' => 'Current revision', 'value' => substr($sha, 0, 12), 'title' => $sha],
                        ],
                        'link' => 'https://github.com/'.config('ota.content.owner').'/'.config('ota.content.repo').'/commit/'.$sha,
                        'link_label' => 'View current commit',
                    ];
                },
            ),
            $this->check(
                title: 'Publication pipeline',
                description: 'The most recent Ksp2Redux GitLab pipeline.',
                icon: 'heroicon-o-rocket-launch',
                callback: function (): array {
                    $pipeline = app(GitLabClient::class)->latest();
                    if ($pipeline === []) {
                        return [
                            'status' => 'Connected',
                            'details' => [['label' => 'Latest pipeline', 'value' => 'No pipeline found']],
                        ];
                    }

                    return [
                        'status' => 'Connected',
                        'details' => [
                            ['label' => 'Pipeline', 'value' => '#'.($pipeline['id'] ?? 'Unknown')],
                            ['label' => 'Result', 'value' => str((string) ($pipeline['status'] ?? 'unknown'))->replace('_', ' ')->headline()],
                            ['label' => 'Source branch', 'value' => $pipeline['ref'] ?? 'Unknown'],
                            ['label' => 'Last updated', 'value' => $this->formatDate($pipeline['updated_at'] ?? null)],
                        ],
                        'link' => $pipeline['web_url'] ?? null,
                        'link_label' => 'View pipeline in GitLab',
                    ];
                },
            ),
            $this->check(
                title: 'Authoring compatibility',
                description: 'Validation rules for the oldest supported KSP2 Redux client.',
                icon: 'heroicon-o-shield-check',
                callback: function (): array {
                    $catalog = app(CompatibilityCatalog::class);
                    $counts = $catalog->counts();
                    $sha = $catalog->sourceSha();

                    return [
                        'status' => 'Catalog loaded',
                        'details' => [
                            ['label' => 'Catalog version', 'value' => (string) $catalog->version()],
                            ['label' => 'Source revision', 'value' => substr($sha, 0, 12), 'title' => $sha],
                            ['label' => 'Supported bodies', 'value' => (string) $counts['bodies']],
                            ['label' => 'Mission node types', 'value' => (string) $counts['mission_types']],
                            ['label' => 'Bundled missions', 'value' => (string) $counts['bundled_missions']],
                        ],
                    ];
                },
            ),
        ];

        return ['cards' => $cards, 'checkedAt' => now()];
    }

    private function check(string $title, string $description, string $icon, callable $callback): array
    {
        try {
            return compact('title', 'description', 'icon') + ['ok' => true] + $callback();
        } catch (\Throwable $exception) {
            return compact('title', 'description', 'icon') + [
                'ok' => false,
                'status' => 'Unavailable',
                'error' => SecretRedactor::message($exception),
                'details' => [],
            ];
        }
    }

    private function formatDate(mixed $date): string
    {
        if (! is_string($date) || blank($date)) {
            return 'Unknown';
        }

        try {
            return CarbonImmutable::parse($date)->format('M j, Y H:i T');
        } catch (\Throwable) {
            return 'Unknown';
        }
    }
}

<?php

namespace App\Filament\Pages;

use App\Services\Content\CompatibilityCatalog;
use App\Services\Integrations\GitHubContentRepository;
use App\Services\Integrations\GitLabClient;
use App\Support\SecretRedactor;
use Filament\Pages\Page;

class SystemStatus extends Page
{
    protected string $view = 'filament.pages.system-status';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    public function getViewData(): array
    {
        $checks = [];
        foreach (['github' => fn () => ['sha' => app(GitHubContentRepository::class)->headSha()], 'gitlab' => fn () => app(GitLabClient::class)->latest(), 'compatibility' => fn () => ['source_sha' => app(CompatibilityCatalog::class)->sourceSha()]] as $name => $callback) {
            try {
                $checks[$name] = ['ok' => true, 'data' => $callback()];
            } catch (\Throwable $exception) {
                $checks[$name] = ['ok' => false, 'error' => SecretRedactor::message($exception)];
            }
        }

        return compact('checks');
    }
}

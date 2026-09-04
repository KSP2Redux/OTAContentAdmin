<?php

namespace App\Filament\Pages;

use App\Jobs\FlushLocalizations;
use App\Models\PublishRun;
use App\Services\Integrations\GitHubContentRepository;
use App\Services\Integrations\WeblateClient;
use App\Support\SecretRedactor;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

class Localizations extends Page
{
    protected string $view = 'filament.pages.localizations';

    protected static string|\UnitEnum|null $navigationGroup = 'OTA content';

    protected function getHeaderActions(): array
    {
        return [Action::make('flush')->label('Flush and publish')->icon('heroicon-o-language')->color('success')->requiresConfirmation()
            ->modalDescription('Commit and push Weblate, run the existing GitLab OTA job, and verify Content/main?')
            ->action(function (): void {
                $run = PublishRun::create(['user_id' => auth()->id(), 'kind' => 'localization', 'state' => 'queued', 'correlation_id' => (string) Str::uuid()]);
                FlushLocalizations::dispatch($run->id);
                Notification::make()->title('Localization publication queued')->success()->send();
            })];
    }

    public function getViewData(): array
    {
        try {
            return ['status' => app(WeblateClient::class)->status(), 'manifest' => app(GitHubContentRepository::class)->manifest('localizations'), 'error' => null];
        } catch (\Throwable $exception) {
            return ['status' => [], 'manifest' => [], 'error' => SecretRedactor::message($exception)];
        }
    }
}

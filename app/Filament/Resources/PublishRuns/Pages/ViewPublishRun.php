<?php

namespace App\Filament\Resources\PublishRuns\Pages;

use App\Filament\Resources\PublishRuns\PublishRunResource;
use App\Services\Publishing\PublishRunManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPublishRun extends ViewRecord
{
    protected static string $resource = PublishRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('stop')
                ->icon('heroicon-o-stop-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Request this publication to stop? A running worker will stop at its next safe checkpoint. If it is unresponsive, recovery will cancel it and release its lock automatically.')
                ->visible(fn () => in_array($this->record->state, ['queued', 'running', 'cancelling'], true)
                    && empty($this->record->metadata['parent_run_id']))
                ->disabled(fn () => $this->record->state === 'cancelling')
                ->action(function (): void {
                    app(PublishRunManager::class)->requestStop($this->record);
                    Notification::make()->title($this->record->state === 'queued' ? 'Publication cancelled' : 'Stop requested')->success()->send();
                    $this->refreshFormData(['state', 'stage', 'error', 'finished_at']);
                }),
        ];
    }
}

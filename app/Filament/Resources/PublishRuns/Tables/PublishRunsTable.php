<?php

namespace App\Filament\Resources\PublishRuns\Tables;

use App\Services\Publishing\PublishRunManager;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PublishRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')->badge(), TextColumn::make('state')->badge()->sortable(), TextColumn::make('stage'),
                TextColumn::make('changeSet.summary')->label('Change set')->limit(50), TextColumn::make('user.name')->label('Publisher'),
                TextColumn::make('created_at')->since()->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('stop')
                    ->icon('heroicon-o-stop-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Request this publication to stop? A running worker will stop at its next safe checkpoint. If it is unresponsive, recovery will cancel it and release its lock automatically.')
                    ->visible(fn ($record) => in_array($record->state, ['queued', 'running', 'cancelling'], true)
                        && empty($record->metadata['parent_run_id']))
                    ->disabled(fn ($record) => $record->state === 'cancelling')
                    ->action(function ($record): void {
                        app(PublishRunManager::class)->requestStop($record);
                        Notification::make()->title($record->state === 'queued' ? 'Publication cancelled' : 'Stop requested')->success()->send();
                    }),
            ])->defaultSort('created_at', 'desc');
    }
}

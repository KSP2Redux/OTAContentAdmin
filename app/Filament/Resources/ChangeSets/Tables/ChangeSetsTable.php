<?php

namespace App\Filament\Resources\ChangeSets\Tables;

use App\Filament\Resources\ChangeSets\ChangeSetResource;
use App\Services\Publishing\ChangeSetValidator;
use App\Services\Publishing\PublishRunManager;
use App\Services\Publishing\RollbackService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

class ChangeSetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('summary')->searchable()->limit(60),
                TextColumn::make('state')->badge()->sortable(),
                TextColumn::make('operations_count')->counts('operations')->label('Changes'),
                TextColumn::make('user.name')->label('Publisher'),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('validate')->icon('heroicon-o-check-badge')
                    ->visible(fn ($record) => in_array($record->state, ['draft', 'failed', 'stale', 'validated'], true) && $record->operations()->exists())
                    ->action(fn ($record) => app(ChangeSetValidator::class)->validate($record)),
                Action::make('publish')->icon('heroicon-o-rocket-launch')->color('success')->requiresConfirmation()
                    ->modalDescription(function ($record): string {
                        $operations = $record->operations()->get();
                        $channels = $operations->pluck('channel')->unique()->join(', ');
                        $deletions = $operations->where('action', 'delete')->pluck('path')->join(', ');

                        return 'Publish '.$operations->count()." staged operation(s) to Content/main? Affected channels: {$channels}.".($deletions ? " Deletions: {$deletions}." : '');
                    })
                    ->visible(fn ($record) => in_array($record->state, ['draft', 'failed', 'stale', 'validated'], true)
                        && $record->operations()->exists()
                        && ! $record->publishRuns()->whereIn('state', ['queued', 'running', 'cancelling'])->exists())
                    ->action(function ($record): void {
                        try {
                            app(PublishRunManager::class)->queueChangeSet($record, auth()->id());
                            Notification::make()->title('Publication queued')->success()->send();
                        } catch (RuntimeException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();
                        }
                    }),
                Action::make('rollback')->icon('heroicon-o-arrow-uturn-left')->color('warning')->requiresConfirmation()
                    ->modalDescription('Create a new inverse change set against the current Content/main? Git history will not be rewritten.')
                    ->visible(fn ($record) => $record->state === 'published')
                    ->action(fn ($record) => redirect(ChangeSetResource::getUrl('view', ['record' => app(RollbackService::class)->createInverse($record, auth()->id())]))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn () => false),
                ]),
            ]);
    }
}

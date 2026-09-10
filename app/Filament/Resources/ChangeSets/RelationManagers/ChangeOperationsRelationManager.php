<?php

namespace App\Filament\Resources\ChangeSets\RelationManagers;

use App\Filament\Resources\ChangeSets\Pages\EditChangeSet;
use App\Models\ChangeOperation;
use App\Services\Publishing\ChangeOperationRemover;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ChangeOperationsRelationManager extends RelationManager
{
    protected static string $relationship = 'operations';

    protected static ?string $title = 'Staged changes';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === EditChangeSet::class && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Review the operations in this change set. Removing one does not affect published content.')
            ->recordTitleAttribute('path')
            ->columns([
                TextColumn::make('channel')
                    ->label('Content type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'main-menu-vessels' => 'Vessel',
                        'missions' => 'Mission',
                        default => ucfirst($state),
                    }),
                TextColumn::make('action')
                    ->label('Change')
                    ->badge(),
                TextColumn::make('path')
                    ->label('File')
                    ->searchable(),
                TextColumn::make('details')
                    ->label('Details')
                    ->getStateUsing(function (ChangeOperation $record): string {
                        $state = $record->metadata ?? [];
                        $details = array_filter([
                            isset($state['author']) ? 'Author: '.$state['author'] : null,
                            isset($state['body']) ? 'Body: '.$state['body'] : null,
                            isset($state['id']) ? 'ID: '.$state['id'] : null,
                        ]);

                        return $details ? implode(' · ', $details) : '—';
                    })
                    ->wrap(),
                TextColumn::make('order_position')
                    ->label('Order')
                    ->placeholder('Unchanged'),
            ])
            ->defaultSort('created_at')
            ->emptyStateHeading('No staged changes')
            ->emptyStateDescription('Add a vessel or mission operation to this change set first.')
            ->recordActions([
                Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remove staged change?')
                    ->modalDescription(fn (ChangeOperation $record): string => "Remove {$record->action} for {$record->path} from this change set? Published content will not be affected.")
                    ->visible(fn (): bool => in_array($this->getOwnerRecord()->state, ['draft', 'validated', 'failed', 'stale'], true))
                    ->action(function (ChangeOperation $record): void {
                        app(ChangeOperationRemover::class)->remove($record);

                        Notification::make()
                            ->title('Staged change removed')
                            ->body('The change set must be validated again before publication.')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}

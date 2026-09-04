<?php

namespace App\Filament\Resources\PublishRuns\Tables;

use Filament\Actions\ViewAction;
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
            ])->defaultSort('created_at', 'desc');
    }
}

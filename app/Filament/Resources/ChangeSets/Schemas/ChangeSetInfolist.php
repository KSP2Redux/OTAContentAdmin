<?php

namespace App\Filament\Resources\ChangeSets\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ChangeSetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Change set')->schema([
                    TextEntry::make('summary'), TextEntry::make('state')->badge(), TextEntry::make('user.name')->label('Publisher'),
                    TextEntry::make('base_content_sha')->copyable(), TextEntry::make('published_sha')->copyable(),
                ])->columns(2),
                RepeatableEntry::make('operations')->schema([
                    TextEntry::make('channel')->badge(), TextEntry::make('action')->badge(), TextEntry::make('path'), TextEntry::make('metadata')->formatStateUsing(fn ($state) => json_encode($state, JSON_UNESCAPED_SLASHES)),
                ])->columns(4)->columnSpanFull(),
                TextEntry::make('validation_report')->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))->columnSpanFull(),
            ]);
    }
}

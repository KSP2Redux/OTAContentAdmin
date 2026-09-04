<?php

namespace App\Filament\Resources\ChangeSets\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ChangeSetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('summary')->required()->maxLength(255)->helperText('Describe the player-visible content change.'),
                TextInput::make('state')->disabled()->dehydrated(false),
                Textarea::make('validation_report')->disabled()->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $state)->columnSpanFull(),
            ]);
    }
}

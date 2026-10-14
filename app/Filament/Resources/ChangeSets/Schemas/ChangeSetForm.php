<?php

namespace App\Filament\Resources\ChangeSets\Schemas;

use App\Filament\Support\ChangeSetDrafts;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ChangeSetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ChangeSetDrafts::summaryField(),
                TextInput::make('state')->disabled()->dehydrated(false),
                Textarea::make('validation_report')->disabled()->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $state)->columnSpanFull(),
            ]);
    }
}

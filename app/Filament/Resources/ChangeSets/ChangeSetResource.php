<?php

namespace App\Filament\Resources\ChangeSets;

use App\Filament\Resources\ChangeSets\Pages\CreateChangeSet;
use App\Filament\Resources\ChangeSets\Pages\EditChangeSet;
use App\Filament\Resources\ChangeSets\Pages\ListChangeSets;
use App\Filament\Resources\ChangeSets\Pages\ViewChangeSet;
use App\Filament\Resources\ChangeSets\Schemas\ChangeSetForm;
use App\Filament\Resources\ChangeSets\Schemas\ChangeSetInfolist;
use App\Filament\Resources\ChangeSets\Tables\ChangeSetsTable;
use App\Models\ChangeSet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ChangeSetResource extends Resource
{
    protected static ?string $model = ChangeSet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'summary';

    protected static string|\UnitEnum|null $navigationGroup = 'Publishing';

    public static function form(Schema $schema): Schema
    {
        return ChangeSetForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ChangeSetInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChangeSetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChangeSets::route('/'),
            'create' => CreateChangeSet::route('/create'),
            'view' => ViewChangeSet::route('/{record}'),
            'edit' => EditChangeSet::route('/{record}/edit'),
        ];
    }
}

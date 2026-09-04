<?php

namespace App\Filament\Resources\PublishRuns;

use App\Filament\Resources\PublishRuns\Pages\ListPublishRuns;
use App\Filament\Resources\PublishRuns\Pages\ViewPublishRun;
use App\Filament\Resources\PublishRuns\Schemas\PublishRunForm;
use App\Filament\Resources\PublishRuns\Schemas\PublishRunInfolist;
use App\Filament\Resources\PublishRuns\Tables\PublishRunsTable;
use App\Models\PublishRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PublishRunResource extends Resource
{
    protected static ?string $model = PublishRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Activity';

    protected static string|\UnitEnum|null $navigationGroup = 'Publishing';

    protected static ?string $recordTitleAttribute = 'correlation_id';

    public static function form(Schema $schema): Schema
    {
        return PublishRunForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PublishRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PublishRunsTable::configure($table);
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
            'index' => ListPublishRuns::route('/'),
            'view' => ViewPublishRun::route('/{record}'),
        ];
    }
}

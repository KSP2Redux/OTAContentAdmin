<?php

namespace App\Filament\Resources\ChangeSets\Pages;

use App\Filament\Resources\ChangeSets\ChangeSetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChangeSets extends ListRecords
{
    protected static string $resource = ChangeSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

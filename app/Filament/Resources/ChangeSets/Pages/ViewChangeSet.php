<?php

namespace App\Filament\Resources\ChangeSets\Pages;

use App\Filament\Resources\ChangeSets\ChangeSetResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewChangeSet extends ViewRecord
{
    protected static string $resource = ChangeSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

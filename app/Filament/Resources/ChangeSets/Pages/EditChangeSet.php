<?php

namespace App\Filament\Resources\ChangeSets\Pages;

use App\Filament\Resources\ChangeSets\ChangeSetResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditChangeSet extends EditRecord
{
    protected static string $resource = ChangeSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

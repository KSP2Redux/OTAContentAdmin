<?php

namespace App\Filament\Resources\PublishRuns\Pages;

use App\Filament\Resources\PublishRuns\PublishRunResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPublishRun extends EditRecord
{
    protected static string $resource = PublishRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\PublishRuns\Pages;

use App\Filament\Resources\PublishRuns\PublishRunResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPublishRun extends ViewRecord
{
    protected static string $resource = PublishRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

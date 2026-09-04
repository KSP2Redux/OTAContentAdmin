<?php

namespace App\Filament\Resources\PublishRuns\Pages;

use App\Filament\Resources\PublishRuns\PublishRunResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPublishRuns extends ListRecords
{
    protected static string $resource = PublishRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

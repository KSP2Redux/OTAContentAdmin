<?php

namespace App\Filament\Resources\ChangeSets\Pages;

use App\Filament\Resources\ChangeSets\ChangeSetResource;
use App\Filament\Support\ChangeSetDrafts;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateChangeSet extends CreateRecord
{
    protected static string $resource = ChangeSetResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return ChangeSetDrafts::create($data, notify: false);
    }
}

<?php

namespace App\Filament\Resources\ChangeSets\Pages;

use App\Filament\Resources\ChangeSets\ChangeSetResource;
use App\Services\Integrations\GitHubContentRepository;
use Filament\Resources\Pages\CreateRecord;

class CreateChangeSet extends CreateRecord
{
    protected static string $resource = ChangeSetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['base_content_sha'] = app(GitHubContentRepository::class)->headSha();
        $data['state'] = 'draft';

        return $data;
    }
}

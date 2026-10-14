<?php

namespace App\Filament\Pages;

use App\Filament\Support\ChangeSetDrafts;
use App\Models\ChangeSet;
use App\Services\Content\CompatibilityCatalog;
use Filament\Actions\Action;
use Filament\Pages\Page;

class VesselEditor extends Page
{
    protected string $view = 'filament.pages.vessel-editor';

    protected static ?string $title = 'Vessel editor';

    protected static string|\UnitEnum|null $navigationGroup = 'OTA content';

    protected static ?int $navigationSort = 2;

    public function createChangeSetAction(): Action
    {
        return ChangeSetDrafts::action('createChangeSet', function (ChangeSet $changeSet): void {
            $this->dispatch('ota-change-set-created', id: $changeSet->getKey(), summary: $changeSet->summary);
        });
    }

    public function getViewData(): array
    {
        return ['editorConfig' => [
            'convertUrl' => route('vessel.editor.convert'), 'editUrl' => route('vessel.editor.edit'), 'stageUrl' => route('vessel.editor.stage'),
            'catalogUrl' => route('vessel.models.catalog'), 'publishedUrl' => url('/admin/vessel-tools/published'),
            'path' => request()->query('path'), 'bodyOptions' => app(CompatibilityCatalog::class)->bodies(),
            'operation' => request()->query('operation'), 'draftUrl' => url('/admin/vessel-tools/draft'),
            'changeSets' => collect(ChangeSetDrafts::options())->map(fn (string $summary, string $id): array => ['id' => $id, 'summary' => $summary])->values()->all(),
            'csrf' => csrf_token(),
        ]];
    }
}

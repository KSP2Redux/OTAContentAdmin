<?php

namespace App\Filament\Pages;

use App\Models\ChangeSet;
use App\Services\Content\CompatibilityCatalog;
use Filament\Pages\Page;

class VesselEditor extends Page
{
    protected string $view = 'filament.pages.vessel-editor';

    protected static ?string $title = 'Vessel editor';

    protected static string|\UnitEnum|null $navigationGroup = 'OTA content';

    protected static ?int $navigationSort = 2;

    public function getViewData(): array
    {
        return ['editorConfig' => [
            'convertUrl' => route('vessel.editor.convert'), 'editUrl' => route('vessel.editor.edit'), 'stageUrl' => route('vessel.editor.stage'),
            'catalogUrl' => route('vessel.models.catalog'), 'publishedUrl' => url('/admin/vessel-tools/published'),
            'path' => request()->query('path'), 'bodyOptions' => app(CompatibilityCatalog::class)->bodies(),
            'operation' => request()->query('operation'), 'draftUrl' => url('/admin/vessel-tools/draft'),
            'changeSets' => ChangeSet::query()->whereIn('state', ['draft', 'validated'])->get(['id', 'summary'])->toArray(),
            'csrf' => csrf_token(),
        ]];
    }
}

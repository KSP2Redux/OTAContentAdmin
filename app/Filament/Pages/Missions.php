<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ChangeSets\ChangeSetResource;
use App\Models\ChangeSet;
use App\Services\Content\CompatibilityCatalog;
use App\Services\Content\ContentHandlerRegistry;
use App\Services\Content\Data\UploadedArtifact;
use App\Services\Integrations\GitHubContentRepository;
use App\Services\Publishing\OperationStager;
use App\Support\SecretRedactor;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Missions extends Page
{
    protected string $view = 'filament.pages.missions';

    protected static string|\UnitEnum|null $navigationGroup = 'OTA content';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newChangeSet')->label('New change set')->url(ChangeSetResource::getUrl('create')),
            $this->uploadAction('add', 'Add mission'), $this->uploadAction('replace', 'Replace mission'),
            Action::make('reorder')->label('Reorder mission')->schema([
                $this->changeSetSelect(), Select::make('path')->options($this->pathOptions())->required(),
                TextInput::make('order_position')->numeric()->minValue(1)->required(),
            ])->action(fn (array $data) => $this->stage('reorder', $data)),
            Action::make('delete')->label('Delete mission')->color('danger')->requiresConfirmation()->schema([$this->changeSetSelect(), Select::make('path')->options($this->pathOptions())->required()])->action(fn (array $data) => $this->stage('delete', $data)),
        ];
    }

    public function getViewData(): array
    {
        try {
            $github = app(GitHubContentRepository::class);
            $handler = app(ContentHandlerRegistry::class)->for('missions');
            $items = [];
            foreach ($github->manifest('missions')['files'] ?? [] as $entry) {
                $items[] = $entry + $handler->inspect(new UploadedArtifact($entry['path'], $github->file('missions', $entry['path']), $entry))->metadata;
            }

            return ['items' => $items, 'bundled' => app(CompatibilityCatalog::class)->bundledMissionIds(), 'error' => null];
        } catch (\Throwable $exception) {
            return ['items' => [], 'bundled' => app(CompatibilityCatalog::class)->bundledMissionIds(), 'error' => SecretRedactor::message($exception)];
        }
    }

    private function uploadAction(string $action, string $label): Action
    {
        $fields = [$this->changeSetSelect()];
        if ($action === 'replace') {
            $fields[] = Select::make('path')->options($this->pathOptions())->required();
        }

        return Action::make($action)->label($label)->schema([...$fields,
            FileUpload::make('upload')->disk('ota-private')->directory('drafts/missions')->acceptedFileTypes(['application/json', 'text/plain'])->maxSize(2048)->required(),
            TextInput::make('order_position')->numeric()->minValue(1),
            KeyValue::make('localization_sources')->keyLabel('Localization key')->valueLabel('English source text')->helperText('Add English text for new mission keys. Existing Weblate keys are safely ignored.'),
        ])->action(function (array $data) use ($action): void {
            $data['metadata'] = ['localization_sources' => $data['localization_sources'] ?? []];
            $this->stage($action, $data);
        });
    }

    private function stage(string $action, array $data): void
    {
        app(OperationStager::class)->stage('missions', $action, $data);
        Notification::make()->title('Mission change staged')->success()->send();
    }

    private function changeSetSelect(): Select
    {
        return Select::make('change_set_id')->label('Draft change set')->options(ChangeSet::query()->whereIn('state', ['draft', 'validated'])->pluck('summary', 'id'))->required();
    }

    private function pathOptions(): array
    {
        return collect(app(GitHubContentRepository::class)->manifest('missions')['files'] ?? [])->pluck('path', 'path')->all();
    }
}

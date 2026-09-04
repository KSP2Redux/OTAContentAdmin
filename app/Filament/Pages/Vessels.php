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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Vessels extends Page
{
    protected string $view = 'filament.pages.vessels';

    protected static ?string $navigationLabel = 'Menu vessels';

    protected static string|\UnitEnum|null $navigationGroup = 'OTA content';

    protected static ?string $title = 'Main-menu vessels';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newChangeSet')->label('New change set')->url(ChangeSetResource::getUrl('create')),
            $this->uploadAction('add', 'Add vessel'), $this->uploadAction('replace', 'Replace vessel'),
            Action::make('reorder')->label('Reorder vessel')->schema([
                $this->changeSetSelect(), Select::make('path')->options($this->pathOptions())->required(),
                TextInput::make('order_position')->numeric()->minValue(1)->required(),
            ])->action(fn (array $data) => $this->stage('reorder', $data)),
            Action::make('delete')->label('Delete vessel')->color('danger')->requiresConfirmation()->schema([
                $this->changeSetSelect(), Select::make('path')->options($this->pathOptions())->required(),
            ])->action(fn (array $data) => $this->stage('delete', $data)),
        ];
    }

    public function getViewData(): array
    {
        try {
            $github = app(GitHubContentRepository::class);
            $handler = app(ContentHandlerRegistry::class)->for('main-menu-vessels');
            $items = [];
            foreach ($github->manifest('main-menu-vessels')['files'] ?? [] as $entry) {
                $inspection = $handler->inspect(new UploadedArtifact($entry['path'], $github->file('main-menu-vessels', $entry['path']), $entry));
                $items[] = $entry + $inspection->metadata;
            }

            return ['items' => $items, 'error' => null];
        } catch (\Throwable $exception) {
            return ['items' => [], 'error' => SecretRedactor::message($exception)];
        }
    }

    private function uploadAction(string $action, string $label): Action
    {
        $fields = [$this->changeSetSelect()];
        if ($action === 'add') {
            $fields[] = TextInput::make('slug')->required()->regex('/^[a-z0-9][a-z0-9-]*$/');
        } else {
            $fields[] = Select::make('path')->options($this->pathOptions())->required();
        }

        return Action::make($action)->label($label)->schema([...$fields,
            FileUpload::make('upload')->disk('ota-private')->directory('drafts/vessels')->acceptedFileTypes(['application/json', 'text/plain'])->maxSize(10240)->required(),
            TextInput::make('author')->required(), Select::make('body')->options(array_combine(app(CompatibilityCatalog::class)->bodies(), app(CompatibilityCatalog::class)->bodies()))->searchable()->required(),
            TextInput::make('order_position')->numeric()->minValue(1),
        ])->action(function (array $data) use ($action): void {
            $data['metadata'] = ['author' => $data['author'], 'body' => $data['body']];
            $this->stage($action, $data);
        });
    }

    private function stage(string $action, array $data): void
    {
        app(OperationStager::class)->stage('main-menu-vessels', $action, $data);
        Notification::make()->title('Vessel change staged')->success()->send();
    }

    private function changeSetSelect(): Select
    {
        return Select::make('change_set_id')->label('Draft change set')->options(ChangeSet::query()->whereIn('state', ['draft', 'validated'])->pluck('summary', 'id'))->required();
    }

    private function pathOptions(): array
    {
        return collect(app(GitHubContentRepository::class)->manifest('main-menu-vessels')['files'] ?? [])->pluck('path', 'path')->all();
    }
}

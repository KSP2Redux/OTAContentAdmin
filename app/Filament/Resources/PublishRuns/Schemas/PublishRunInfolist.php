<?php

namespace App\Filament\Resources\PublishRuns\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PublishRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Publication')->schema([
                    TextEntry::make('kind')->badge(), TextEntry::make('state')->badge(), TextEntry::make('stage'), TextEntry::make('user.name')->label('Publisher'),
                    TextEntry::make('changeSet.summary')->label('Change set'), TextEntry::make('correlation_id')->copyable(),
                    TextEntry::make('weblate_task_url')->url(fn ($state) => $state)->openUrlInNewTab(),
                    TextEntry::make('gitlab_pipeline_url')->url(fn ($state) => $state)->openUrlInNewTab(),
                    TextEntry::make('github_commit_url')->url(fn ($state) => $state)->openUrlInNewTab(),
                    TextEntry::make('started_at')->dateTime(), TextEntry::make('finished_at')->dateTime(),
                ])->columns(2),
                TextEntry::make('error')->color('danger')->columnSpanFull(),
                TextEntry::make('metadata')->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))->columnSpanFull(),
            ]);
    }
}

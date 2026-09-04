<?php

namespace App\Filament\Resources\PublishRuns\Schemas;

use Filament\Schemas\Schema;

class PublishRunForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Publication records are immutable and are only created by queued jobs.
            ]);
    }
}

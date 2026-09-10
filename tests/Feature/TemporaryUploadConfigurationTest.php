<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TemporaryUploadConfigurationTest extends TestCase
{
    public function test_livewire_temporary_uploads_use_the_private_writable_disk(): void
    {
        $this->assertSame('ota-private', config('livewire.temporary_file_upload.disk'));
        $this->assertSame('livewire-tmp', config('livewire.temporary_file_upload.directory'));
        $this->assertContains('max:10240', config('livewire.temporary_file_upload.rules'));

        Storage::fake('ota-private');
        Storage::disk(config('livewire.temporary_file_upload.disk'))
            ->put(config('livewire.temporary_file_upload.directory').'/upload.json', '{}');

        Storage::disk('ota-private')->assertExists('livewire-tmp/upload.json');
    }
}

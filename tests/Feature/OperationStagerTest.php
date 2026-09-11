<?php

namespace Tests\Feature;

use App\Models\ChangeOperation;
use App\Models\ChangeSet;
use App\Models\User;
use App\Services\Publishing\ChangeSetValidator;
use App\Services\Publishing\OperationStager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationStagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_is_moved_to_a_changeset_owned_payload_before_staging(): void
    {
        Storage::persistentFake('ota-private');
        Http::fake([
            rtrim(config('ota.content.raw_url'), '/').'/main-menu-vessels/manifest.json' => Http::response(['files' => []]),
        ]);
        $user = User::factory()->create();
        $changeSet = ChangeSet::create([
            'user_id' => $user->id,
            'summary' => 'Add a vessel',
            'state' => 'validated',
            'validation_report' => ['valid' => true],
        ]);
        $sourcePath = 'drafts/vessels/upload.json';
        $contents = '{"Metadata":{"WorkspaceName":"Test Craft","Mass":1.5,"Parts":1},"Assemblies":[{"boundsSize":{"x":1,"y":2,"z":3},"Parts":[{"id":"one"}]}]}';
        Storage::disk('ota-private')->put($sourcePath, $contents);

        $operation = app(OperationStager::class)->stage('main-menu-vessels', 'add', [
            'change_set_id' => $changeSet->id,
            'slug' => 'test-craft',
            'upload' => $sourcePath,
            'metadata' => ['author' => 'Tester', 'body' => 'Kerbin'],
        ]);

        $this->assertStringStartsWith("drafts/change-sets/{$changeSet->id}/main-menu-vessels/", $operation->payload_path);
        $this->assertSame($contents, Storage::disk('ota-private')->get($operation->payload_path));
        Storage::disk('ota-private')->assertMissing($sourcePath);
        $this->assertDatabaseHas('change_sets', [
            'id' => $changeSet->id,
            'state' => 'draft',
            'validation_report' => null,
        ]);
    }

    public function test_restaging_the_same_file_removes_the_replaced_owned_payload(): void
    {
        Storage::persistentFake('ota-private');
        Http::fake([
            rtrim(config('ota.content.raw_url'), '/').'/main-menu-vessels/manifest.json' => Http::response(['files' => []]),
        ]);
        $changeSet = ChangeSet::create([
            'user_id' => User::factory()->create()->id,
            'summary' => 'Add a vessel',
            'state' => 'draft',
        ]);
        $contents = '{"Metadata":{"WorkspaceName":"Test Craft","Mass":1.5,"Parts":1},"Assemblies":[{"boundsSize":{"x":1,"y":2,"z":3},"Parts":[{"id":"one"}]}]}';
        Storage::disk('ota-private')->put('drafts/vessels/first.json', $contents);
        $first = app(OperationStager::class)->stage('main-menu-vessels', 'add', [
            'change_set_id' => $changeSet->id,
            'slug' => 'test-craft',
            'upload' => 'drafts/vessels/first.json',
            'metadata' => ['author' => 'Tester', 'body' => 'Kerbin'],
        ]);
        $firstOwnedPath = $first->payload_path;

        Storage::disk('ota-private')->put('drafts/vessels/second.json', $contents);
        $second = app(OperationStager::class)->stage('main-menu-vessels', 'add', [
            'change_set_id' => $changeSet->id,
            'slug' => 'test-craft',
            'upload' => 'drafts/vessels/second.json',
            'metadata' => ['author' => 'Updated', 'body' => 'Kerbin'],
        ]);

        $this->assertNotSame($firstOwnedPath, $second->payload_path);
        Storage::disk('ota-private')->assertMissing($firstOwnedPath);
        Storage::disk('ota-private')->assertExists($second->payload_path);
        $this->assertSame(1, $changeSet->operations()->count());
    }

    public function test_missing_legacy_payload_has_an_actionable_validation_error(): void
    {
        Storage::persistentFake('ota-private');
        Http::fake([
            rtrim(config('ota.content.raw_url'), '/').'/main-menu-vessels/manifest.json' => Http::response(['files' => []]),
        ]);
        $changeSet = ChangeSet::create([
            'user_id' => User::factory()->create()->id,
            'summary' => 'Missing upload',
            'state' => 'validated',
        ]);
        ChangeOperation::create([
            'change_set_id' => $changeSet->id,
            'channel' => 'main-menu-vessels',
            'action' => 'add',
            'path' => 'missing.json',
            'payload_path' => 'drafts/vessels/missing.json',
        ]);

        $report = app(ChangeSetValidator::class)->validate($changeSet);

        $this->assertFalse($report['valid']);
        $this->assertContains(
            'main-menu-vessels/missing.json no longer has its staged upload. Remove this change and add it again.',
            $report['errors'],
        );
    }
}

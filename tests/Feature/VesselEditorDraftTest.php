<?php

namespace Tests\Feature;

use App\Models\ChangeOperation;
use App\Models\ChangeSet;
use App\Models\User;
use App\Services\Publishing\PayloadStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\VesselWorkspaceTest;

class VesselEditorDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_payload_can_be_reopened_but_publishing_payload_cannot_be_edited(): void
    {
        Storage::fake('ota-payloads');
        $user = User::factory()->create(['groups' => [config('ota.auth.publisher_group')]]);
        $changeSet = ChangeSet::create(['summary' => 'Draft', 'state' => 'draft']);
        $operation = ChangeOperation::create(['change_set_id' => $changeSet->id, 'channel' => 'main-menu-vessels', 'action' => 'add', 'path' => 'station.json',
            'payload_path' => 'drafts/change-sets/test/station.json', 'metadata' => ['author' => 'Tester', 'body' => 'Pol']]);
        app(PayloadStorage::class)->put($operation->payload_path, VesselWorkspaceTest::workspace());
        $this->actingAs($user)->getJson('/admin/vessel-tools/draft/'.$operation->id)->assertOk()->assertJsonPath('published.body', 'Pol')->assertJsonPath('published.change_set_id', $changeSet->id);
        $changeSet->update(['state' => 'publishing']);
        $this->getJson('/admin/vessel-tools/draft/'.$operation->id)->assertStatus(409);
    }
}

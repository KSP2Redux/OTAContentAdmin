<?php

namespace Tests\Feature;

use App\Filament\Resources\ChangeSets\Pages\EditChangeSet;
use App\Filament\Resources\ChangeSets\RelationManagers\ChangeOperationsRelationManager;
use App\Models\ChangeOperation;
use App\Models\ChangeSet;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ChangeSetEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_page_lists_staged_changes(): void
    {
        $user = User::factory()->create(['groups' => [config('ota.auth.publisher_group')]]);
        $changeSet = ChangeSet::create([
            'user_id' => $user->id,
            'summary' => 'Update a vessel',
            'state' => 'draft',
        ]);
        $operation = ChangeOperation::create([
            'change_set_id' => $changeSet->id,
            'channel' => 'main-menu-vessels',
            'action' => 'replace',
            'path' => 'test-craft.json',
            'metadata' => ['author' => 'Tester', 'body' => 'Kerbin'],
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(ChangeOperationsRelationManager::class, [
                'ownerRecord' => $changeSet,
                'pageClass' => EditChangeSet::class,
            ])
            ->assertCanSeeTableRecords([$operation])
            ->assertSee('Staged changes')
            ->assertSee('test-craft.json')
            ->assertSee('Author: Tester')
            ->assertTableActionVisible('remove', $operation);
    }

    public function test_removing_a_staged_change_deletes_its_payload_and_invalidates_validation(): void
    {
        Storage::fake('ota-private');
        Storage::disk('ota-private')->put('drafts/vessels/test-craft.json', '{}');
        $user = User::factory()->create(['groups' => [config('ota.auth.publisher_group')]]);
        $changeSet = ChangeSet::create([
            'user_id' => $user->id,
            'summary' => 'Update a vessel',
            'state' => 'validated',
            'validation_report' => ['valid' => true],
        ]);
        $operation = ChangeOperation::create([
            'change_set_id' => $changeSet->id,
            'channel' => 'main-menu-vessels',
            'action' => 'replace',
            'path' => 'test-craft.json',
            'payload_path' => 'drafts/vessels/test-craft.json',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(ChangeOperationsRelationManager::class, [
                'ownerRecord' => $changeSet,
                'pageClass' => EditChangeSet::class,
            ])
            ->callTableAction('remove', $operation)
            ->assertNotified('Staged change removed');

        $this->assertDatabaseMissing('change_operations', ['id' => $operation->id]);
        $this->assertDatabaseHas('change_sets', [
            'id' => $changeSet->id,
            'state' => 'draft',
            'validation_report' => null,
        ]);
        Storage::disk('ota-private')->assertMissing('drafts/vessels/test-craft.json');
    }

    public function test_published_changes_cannot_be_removed(): void
    {
        $user = User::factory()->create(['groups' => [config('ota.auth.publisher_group')]]);
        $changeSet = ChangeSet::create([
            'user_id' => $user->id,
            'summary' => 'Already published',
            'state' => 'published',
        ]);
        $operation = ChangeOperation::create([
            'change_set_id' => $changeSet->id,
            'channel' => 'missions',
            'action' => 'delete',
            'path' => 'mission.json',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(ChangeOperationsRelationManager::class, [
                'ownerRecord' => $changeSet,
                'pageClass' => EditChangeSet::class,
            ])
            ->assertTableActionHidden('remove', $operation);
    }
}

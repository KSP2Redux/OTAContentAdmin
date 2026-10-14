<?php

namespace Tests\Feature;

use App\Filament\Pages\Missions;
use App\Filament\Pages\VesselEditor;
use App\Filament\Pages\Vessels;
use App\Filament\Resources\ChangeSets\Pages\CreateChangeSet;
use App\Models\ChangeSet;
use App\Models\User;
use App\Services\Publishing\ChangeSetCreator;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InlineChangeSetTest extends TestCase
{
    use RefreshDatabase;

    private bool $repositoryUnavailable = false;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/git/ref/heads/')) {
                return $this->repositoryUnavailable
                    ? Http::response([], 503)
                    : Http::response(['object' => ['sha' => str_repeat('a', 40)]]);
            }

            return Http::response(['files' => [['path' => 'test.json', 'order' => 1, 'author' => 'Tester', 'body' => 'Pol', 'sha256' => str_repeat('b', 64)]]]);
        });
    }

    private function publisher(): User
    {
        return User::factory()->create(['groups' => [config('ota.auth.publisher_group')]]);
    }

    public function test_editor_creates_an_owned_draft_and_announces_it_without_staging_or_publishing(): void
    {
        $user = $this->publisher();
        $component = Livewire::actingAs($user)->test(VesselEditor::class)
            ->callAction('createChangeSet', ['summary' => '  Update stations  '])
            ->assertHasNoActionErrors();
        $draft = ChangeSet::sole();
        $component->assertDispatched('ota-change-set-created', id: $draft->id, summary: 'Update stations');
        $this->assertSame($user->id, $draft->user_id);
        $this->assertSame('draft', $draft->state);
        $this->assertSame(str_repeat('a', 40), $draft->base_content_sha);
        $this->assertDatabaseCount('change_operations', 0);
        $this->assertDatabaseCount('publish_runs', 0);
    }

    public static function stagingPages(): array
    {
        return [[Vessels::class], [Missions::class]];
    }

    #[DataProvider('stagingPages')]
    public function test_nested_creation_selects_the_new_draft_and_preserves_the_parent_form(string $page): void
    {
        $component = Livewire::actingAs($this->publisher())->test($page)
            ->mountAction('reorder')
            ->fillForm(['path' => 'test.json', 'order_position' => 3]);
        $component->callAction(TestAction::make('createOption')->schemaComponent('change_set_id', 'mountedActionSchema0'), ['summary' => 'Reorder content'])
            ->assertHasNoActionErrors();
        $draft = ChangeSet::sole();
        $component->assertActionMounted('reorder')->assertActionDataSet([
            'path' => 'test.json', 'order_position' => 3, 'change_set_id' => $draft->id,
        ]);
        $this->assertDatabaseCount('change_operations', 0);
    }

    public function test_cancel_and_invalid_summary_do_not_create_drafts(): void
    {
        $component = Livewire::actingAs($this->publisher())->test(VesselEditor::class)
            ->mountAction('createChangeSet')->fillForm(['summary' => 'Cancelled'])->unmountAction();
        $this->assertDatabaseCount('change_sets', 0);
        $component->callAction('createChangeSet', ['summary' => ' '])->assertHasActionErrors(['summary' => 'required']);
        $this->assertDatabaseCount('change_sets', 0);
    }

    public function test_repository_failure_keeps_the_creation_dialog_open_without_a_partial_draft(): void
    {
        $this->repositoryUnavailable = true;
        Livewire::actingAs($this->publisher())->test(VesselEditor::class)
            ->callAction('createChangeSet', ['summary' => 'Retry this draft'])
            ->assertActionMounted('createChangeSet')
            ->assertActionDataSet(['summary' => 'Retry this draft'])
            ->assertNotified('Could not create change set');
        $this->assertDatabaseCount('change_sets', 0);
    }

    public function test_standalone_creation_uses_the_same_draft_defaults(): void
    {
        $user = $this->publisher();
        Livewire::actingAs($user)->test(CreateChangeSet::class)
            ->fillForm(['summary' => 'Standalone draft'])->call('create')->assertHasNoFormErrors();
        $draft = ChangeSet::sole();
        $this->assertSame($user->id, $draft->user_id);
        $this->assertSame('draft', $draft->state);
        $this->assertSame(str_repeat('a', 40), $draft->base_content_sha);
    }

    public function test_non_publishers_cannot_create_drafts(): void
    {
        $this->actingAs(User::factory()->create(['groups' => []]));
        try {
            app(ChangeSetCreator::class)->create('Unauthorized draft');
            $this->fail('A non-publisher created a draft.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('change_sets', 0);
        Http::assertNothingSent();
    }
}

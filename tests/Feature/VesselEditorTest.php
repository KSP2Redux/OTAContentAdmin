<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\VesselWorkspaceTest;

class VesselEditorTest extends TestCase
{
    use RefreshDatabase;

    private function publisher(): User
    {
        return User::factory()->create(['groups' => [config('ota.auth.publisher_group')]]);
    }

    public function test_editor_and_models_require_publisher_access(): void
    {
        $this->get('/admin/vessel-editor')->assertRedirect();
        $user = User::factory()->create(['groups' => []]);
        $this->actingAs($user)->getJson('/admin/vessel-tools/models')->assertForbidden();
        $this->actingAs($user)->postJson('/admin/vessel-tools/edit', ['contents' => VesselWorkspaceTest::workspace()])->assertForbidden();
    }

    public function test_publisher_can_open_editor_and_convert_uploads(): void
    {
        $this->actingAs($this->publisher())->get('/admin/vessel-editor')->assertOk()->assertSee('Fly in')->assertSee('Deploy all');
        $file = UploadedFile::fake()->createWithContent('station.json', VesselWorkspaceTest::workspace());
        $this->postJson('/admin/vessel-tools/convert', ['file' => $file])->assertOk()->assertJsonPath('metadata.Parts', 2)->assertJsonPath('parts.0.deployed', false);
    }

    public function test_edits_return_the_same_state_used_by_preview_and_download(): void
    {
        $this->actingAs($this->publisher())->postJson('/admin/vessel-tools/edit', ['contents' => VesselWorkspaceTest::workspace(), 'deployment' => ['second' => true]])
            ->assertOk()->assertJsonPath('parts.0.deployed', false)->assertJsonPath('parts.1.deployed', true)->assertJsonPath('parts.1.canDeploy', true);
    }

    public function test_model_bytes_are_served_only_through_private_authorized_routes(): void
    {
        Storage::fake('vessel-models');
        Storage::disk('vessel-models')->put('panel.extended.glb', 'glTFtest');
        Storage::disk('vessel-models')->put('catalog.json', json_encode(['version' => 1, 'parts' => ['panel' => ['extended' => 'panel.extended.glb']], 'resources' => []], JSON_THROW_ON_ERROR));
        $this->actingAs($this->publisher())->get('/admin/vessel-tools/models/panel/extended')->assertOk()->assertHeader('Content-Type', 'model/gltf-binary');
        $this->assertContains($this->get('/storage/vessel-models/panel.extended.glb')->status(), [403, 404]);
        Storage::disk('vessel-models')->put('catalog.json', json_encode(['parts' => ['panel' => ['extended' => '../outside.glb']]], JSON_THROW_ON_ERROR));
        $this->get('/admin/vessel-tools/models/panel/extended')->assertNotFound();
    }

    public function test_procedural_rigs_are_private_and_imported_with_checksums(): void
    {
        Storage::fake('vessel-models');
        $directory = sys_get_temp_dir().'/ota-rig-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $rig = '{"version":1,"procedural":[],"fairings":[]}';
        $catalog = ['version' => 1, 'parts' => ['wing' => ['procedural' => 'wing.procedural.json', 'hashes' => ['procedural' => hash('sha256', $rig)]]]];
        file_put_contents($directory.'/wing.procedural.json', $rig);
        file_put_contents($directory.'/catalog.json', json_encode($catalog, JSON_THROW_ON_ERROR));
        try {
            $this->artisan('vessels:import-models', ['directory' => $directory])->assertSuccessful();
            $this->get('/admin/vessel-tools/models/wing/procedural')->assertRedirect();
            $this->actingAs(User::factory()->create(['groups' => []]))->getJson('/admin/vessel-tools/models/wing/procedural')->assertForbidden();
            $this->actingAs($this->publisher())->get('/admin/vessel-tools/models/wing/procedural')->assertOk()->assertHeader('Content-Type', 'application/json');
            $this->getJson('/admin/vessel-tools/models')->assertJsonPath('parts.wing.procedural', route('vessel.models.file', ['part' => 'wing', 'pose' => 'procedural']).'?v='.hash('sha256', $rig));
        } finally {
            unlink($directory.'/wing.procedural.json');
            unlink($directory.'/catalog.json');
            rmdir($directory);
        }
    }
}

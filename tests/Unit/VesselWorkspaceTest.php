<?php

namespace Tests\Unit;

use App\Services\Vessels\LosslessJsonEditor;
use App\Services\Vessels\VesselWorkspace;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VesselWorkspaceTest extends TestCase
{
    public static function assembly(): array
    {
        $part = fn ($guid, $x, $deployable) => ['PartGuid' => ['Guid' => $guid], 'partName' => 'panel',
            'partState' => ['localPosition' => ['x' => $x, 'y' => 0.0, 'z' => 0.0], 'localRotation' => ['x' => 0, 'y' => 0, 'z' => 0, 'w' => 1], 'UserRotation' => ['x' => 0, 'y' => 0, 'z' => 0, 'w' => 1], 'resources' => ['Fuel' => ['storedUnits' => 1.25]]],
            'PartModulesState' => [['ModuleData' => [['Name' => 'Data_Deployable', 'DataObject' => ['UseAnimation' => $deployable, 'CurrentDeployState' => ['storedValue' => 'Retracted'], 'toggleExtend' => ['storedValue' => false], 'AnimationNormalizedTime' => ['storedValue' => 0.0], 'DeployLimit' => ['storedValue' => 1.0], 'IsExtended' => false, 'IsRetracted' => true, 'IsExtending' => false, 'IsRetracting' => false]]]]]];

        return ['AssemblyDefinition' => ['assemblyName' => 'Station', 'description' => 'Saved description'], 'AssemblyOABConfig' => ['RootGuid' => ['Guid' => 'root'], 'StagingState' => ['totalStageCount' => 0]], 'parts' => [$part('root', 0, true), $part('second', 2, true)]];
    }

    public static function workspace(): string
    {
        return '{"Metadata":{"Name":"Station","VehicleName":"Station","Mass":10.0,"Parts":2,"SaveTime":639275246455830892,"untouched":1.2300000},"Assemblies":[{"isMainAssembly":true,"Assembly":'.json_encode(self::assembly(), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR).'}]}';
    }

    public function test_path_editor_preserves_large_integers_and_unrelated_number_tokens(): void
    {
        $result = app(LosslessJsonEditor::class)->patch('{"tick":639275246455830892,"value":1.2300000,"item":{"flag":false},"values":[2e-3]}', ['/item/flag' => true, '/item/new' => 1.0]);
        $this->assertSame('{"tick":639275246455830892,"value":1.2300000,"item":{"flag":true,"new":1.0},"values":[2e-3]}', $result);
    }

    public function test_assembly_is_wrapped_and_mass_is_computed_from_private_definitions(): void
    {
        Storage::fake('vessel-models');
        Storage::disk('vessel-models')->put('catalog.json', json_encode(['version' => 1, 'parts' => ['panel' => ['mass' => 2]], 'resources' => ['Fuel' => 1]], JSON_THROW_ON_ERROR));
        $result = app(VesselWorkspace::class)->convert(json_encode(self::assembly(), JSON_THROW_ON_ERROR));
        $data = json_decode($result['contents'], true);
        $this->assertSame(2, $data['Metadata']['Parts']);
        $this->assertSame(6.5, $data['Metadata']['Mass']);
        $this->assertTrue($data['Assemblies'][0]['isMainAssembly']);
        $this->assertSame('Station', $result['metadata']['VehicleName']);
    }

    public function test_unknown_assembly_mass_is_reported_instead_of_invented(): void
    {
        Storage::fake('vessel-models');
        $result = app(VesselWorkspace::class)->convert(json_encode(self::assembly(), JSON_THROW_ON_ERROR));
        $this->assertSame(0.0, $result['metadata']['Mass']);
        $this->assertStringContainsString('Enter the vessel mass', implode(' ', $result['notes']));
    }

    public function test_individual_part_edit_preserves_other_parts_resources_and_ticks(): void
    {
        $result = app(VesselWorkspace::class)->edit(self::workspace(), ['deployment' => ['second' => true]]);
        $this->assertFalse($result['parts'][0]['deployed']);
        $this->assertTrue($result['parts'][1]['deployed']);
        $this->assertStringContainsString('639275246455830892', $result['contents']);
        $this->assertStringContainsString('1.2300000', $result['contents']);
        $parts = json_decode($result['contents'], true)['Assemblies'][0]['Assembly']['parts'];
        $this->assertSame(['Fuel' => ['storedUnits' => 1.25]], $parts[1]['partState']['resources']);
        $this->assertSame(1.0, $parts[1]['PartModulesState'][0]['ModuleData'][0]['DataObject']['AnimationNormalizedTime']['storedValue']);
    }

    public function test_bulk_deployment_and_rotation_preserve_internal_geometry(): void
    {
        $result = app(VesselWorkspace::class)->edit(self::workspace(), ['all_deployed' => true, 'rotation' => ['axis' => 'z', 'degrees' => 90]]);
        $this->assertSame([true, true], array_column($result['parts'], 'deployed'));
        $this->assertEqualsWithDelta(0, $result['parts'][1]['position'][0], 1e-12);
        $this->assertEqualsWithDelta(2, $result['parts'][1]['position'][1], 1e-12);
        $retracted = app(VesselWorkspace::class)->edit($result['contents'], ['all_deployed' => false]);
        $this->assertSame([false, false], array_column($retracted['parts'], 'deployed'));
    }

    public function test_unknown_part_cannot_be_edited(): void
    {
        $this->expectException(ValidationException::class);
        app(VesselWorkspace::class)->edit(self::workspace(), ['deployment' => ['missing' => true]]);
    }

    public function test_invalid_json_is_a_validation_error_and_bom_is_accepted(): void
    {
        $result = app(VesselWorkspace::class)->convert("\xEF\xBB\xBF".self::workspace());
        $this->assertSame(2, $result['metadata']['Parts']);
        $this->expectException(ValidationException::class);
        app(VesselWorkspace::class)->convert('{invalid');
    }

    public function test_fixed_parts_are_not_folded_by_bulk_controls(): void
    {
        $document = json_decode(self::workspace(), true);
        $fixed = &$document['Assemblies'][0]['Assembly']['parts'][0]['PartModulesState'][0]['ModuleData'][0]['DataObject'];
        $fixed['UseAnimation'] = false;
        $fixed['CurrentDeployState']['storedValue'] = 'Extended';
        $result = app(VesselWorkspace::class)->edit(json_encode($document, JSON_THROW_ON_ERROR), ['all_deployed' => false]);
        $this->assertTrue($result['parts'][0]['deployed']);
        $this->assertFalse($result['parts'][0]['canDeploy']);
        $this->assertFalse($result['parts'][1]['deployed']);
    }

    public function test_legacy_string_assembly_is_converted_without_losing_numeric_tokens(): void
    {
        $assembly = str_replace('"x":2', '"x":2.00000', json_encode(self::assembly(), JSON_THROW_ON_ERROR));
        $json = '{"Metadata":{"Mass":10,"Parts":2},"Assemblies":[{"isMainAssembly":true,"assembly":'.json_encode($assembly, JSON_THROW_ON_ERROR).'}]}';
        $result = app(VesselWorkspace::class)->convert($json);
        $this->assertStringContainsString('2.00000', $result['contents']);
        $this->assertCount(2, $result['parts']);
    }
}

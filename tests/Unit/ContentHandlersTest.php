<?php

namespace Tests\Unit;

use App\Services\Content\CompatibilityCatalog;
use App\Services\Content\Data\ChangeSetContext;
use App\Services\Content\Data\ChannelSnapshot;
use App\Services\Content\Data\UploadedArtifact;
use App\Services\Content\LosslessJsonNormalizer;
use App\Services\Content\MissionContentHandler;
use App\Services\Content\VesselContentHandler;
use Tests\TestCase;

class ContentHandlersTest extends TestCase
{
    public function test_compatibility_catalog_lists_dres_and_its_moons_together(): void
    {
        $bodies = app(CompatibilityCatalog::class)->bodies();

        $this->assertContains('Beyl', $bodies);
        $this->assertSame(['Dres', 'Drast', 'Beyl'], array_slice($bodies, array_search('Dres', $bodies, true), 3));
    }

    public function test_lossless_normalizer_drops_object_nulls_and_preserves_number_spelling(): void
    {
        $json = '{ "position": 1.2300, "nothing": null, "items": [null, 2e-3], "type": "Thing, Assembly, Version=1.2.3.4, Culture=neutral, PublicKeyToken=null" }';
        $normalized = app(LosslessJsonNormalizer::class)->normalize($json, true);
        $this->assertSame('{"position":1.2300,"items":[null,2e-3],"type":"Thing, Assembly"}', $normalized);
    }

    public function test_vessel_handler_inspects_validates_and_generates_deterministic_manifest(): void
    {
        $handler = new VesselContentHandler(new LosslessJsonNormalizer, app(CompatibilityCatalog::class));
        $artifact = new UploadedArtifact('test-craft.json', '{"Metadata":{"WorkspaceName":"Test Craft","Mass":1.5,"Parts":1},"Assemblies":[{"boundsSize":{"x":1,"y":2,"z":3},"Parts":[{"id":"one"}]}],"unused":null}', ['author' => 'Tester', 'body' => 'Kerbin']);
        $this->assertSame('Test Craft', $handler->inspect($artifact)->metadata['name']);
        $this->assertTrue($handler->validate(new ChangeSetContext($artifact))->passes());
        $normalized = $handler->normalize($artifact);
        $this->assertSame(hash('sha256', $normalized->contents), $normalized->sha256);
        $manifest = $handler->buildManifest(new ChannelSnapshot('main-menu-vessels', [
            ['path' => 'b.json', 'sha256' => str_repeat('b', 64), 'bytes' => 2, 'order' => 9, 'author' => 'B', 'body' => 'Kerbin'],
            ['path' => 'a.json', 'sha256' => str_repeat('a', 64), 'bytes' => 1, 'order' => 2, 'author' => 'A', 'body' => 'Kerbin'],
        ]));
        $this->assertSame(['a.json', 'b.json'], array_column($manifest->files, 'path'));
        $this->assertSame([1, 2], array_column($manifest->files, 'order'));
    }

    public function test_mission_handler_extracts_metadata_and_rejects_unknown_types(): void
    {
        $handler = new MissionContentHandler(new LosslessJsonNormalizer, app(CompatibilityCatalog::class));
        $json = json_encode(['ID' => 'KSP2Mission_Test', 'MissionGroup' => 'Test', 'name' => 'Missions/Test/Name', 'missionStages' => [['StageID' => 0, 'scriptableCondition' => ['$type' => 'Unknown.Type, Assembly-CSharp']]], 'MissionGranterKey' => 'Mission'], JSON_THROW_ON_ERROR);
        $artifact = new UploadedArtifact('ksp2mission-test.json', $json);
        $inspection = $handler->inspect($artifact);
        $this->assertSame(['Missions/Test/Name'], $inspection->metadata['localization_keys']);
        $report = $handler->validate(new ChangeSetContext($artifact, [], ['KSP2Mission_Test']));
        $this->assertFalse($report->passes());
        $this->assertStringContainsString('Unsupported mission node type', implode(' ', $report->errors));
    }
}

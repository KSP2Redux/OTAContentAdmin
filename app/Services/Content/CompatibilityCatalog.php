<?php

namespace App\Services\Content;

use RuntimeException;

final class CompatibilityCatalog
{
    private array $data;

    public function __construct()
    {
        $path = config('ota.compatibility_path');
        $this->data = json_decode((string) @file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        if (empty($this->data['source_sha'])) {
            throw new RuntimeException('Compatibility catalog has no source SHA.');
        }
    }

    public function sourceSha(): string
    {
        return $this->data['source_sha'];
    }

    public function version(): int
    {
        return (int) ($this->data['catalog_version'] ?? 0);
    }

    /** @return array{bodies: int, mission_types: int, bundled_missions: int} */
    public function counts(): array
    {
        return [
            'bodies' => count($this->bodies()),
            'mission_types' => count($this->missionTypes()),
            'bundled_missions' => count($this->bundledMissionIds()),
        ];
    }

    public function bodies(): array
    {
        return $this->data['bodies'] ?? [];
    }

    public function missionTypes(): array
    {
        return $this->data['mission_types'] ?? [];
    }

    public function bundledMissionIds(): array
    {
        return $this->data['bundled_mission_ids'] ?? [];
    }

    public function localizationPattern(): string
    {
        return $this->data['localization_key_pattern'] ?? '~^(Missions|Tutorials)/~';
    }

    public function localizationFields(): array
    {
        return $this->data['localization_fields'] ?? [];
    }
}

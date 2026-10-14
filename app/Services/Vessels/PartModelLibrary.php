<?php

namespace App\Services\Vessels;

use Illuminate\Support\Facades\Storage;

final class PartModelLibrary
{
    public function catalog(): array
    {
        $disk = Storage::disk('vessel-models');

        return $disk->exists('catalog.json')
            ? json_decode($disk->get('catalog.json'), true, 128, JSON_THROW_ON_ERROR)
            : ['version' => 1, 'parts' => [], 'resources' => []];
    }

    public function mass(array $assembly): ?float
    {
        $catalog = $this->catalog();
        $mass = 0.0;
        foreach ($assembly['parts'] as $part) {
            $definition = $catalog['parts'][$part['partName']] ?? null;
            if (! isset($definition['mass'])) {
                return null;
            }
            $mass += $definition['mass'];
            foreach ($part['PartModulesState'] ?? [] as $module) {
                foreach ($module['ModuleData'] ?? [] as $entry) {
                    if (in_array($entry['Name'] ?? '', ['Data_ProceduralPart', 'Data_Fairing'], true)) {
                        $modifier = $entry['DataObject']['MassModifierAmount'] ?? 0;
                        if (is_numeric($modifier) && is_finite((float) $modifier)) {
                            $mass += (float) $modifier;
                        }
                    }
                }
            }
            $saved = $part['partState']['resources'] ?? null;
            $resources = $saved ?? $definition['resources'] ?? [];
            foreach ($resources as $name => $state) {
                $units = $state['storedUnits'] ?? $state['initialUnits'] ?? 0;
                $density = $catalog['resources'][$state['name'] ?? $name] ?? null;
                if ($units && $density === null) {
                    return null;
                }
                $mass += $units * ($density ?? 0);
            }
        }

        return $mass > 0 && is_finite($mass) ? $mass : null;
    }
}

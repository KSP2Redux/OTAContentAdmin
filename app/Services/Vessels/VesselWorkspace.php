<?php

namespace App\Services\Vessels;

use App\Services\Content\LosslessJsonNormalizer;
use Illuminate\Validation\ValidationException;

final readonly class VesselWorkspace
{
    public function __construct(private LosslessJsonNormalizer $normalizer, private LosslessJsonEditor $editor, private PartModelLibrary $models) {}

    public function convert(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        try {
            $contents = $this->normalizer->normalize($contents, true);
        } catch (\JsonException $exception) {
            throw ValidationException::withMessages(['file' => 'The vessel file is not valid JSON.']);
        }
        if (strlen($contents) > config('ota.limits.vessel')) {
            throw ValidationException::withMessages(['file' => 'The normalized vessel exceeds the 10 MiB OTA limit.']);
        }
        $data = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
        $notes = [];
        if (isset($data['parts'], $data['AssemblyDefinition'])) {
            $name = $data['AssemblyDefinition']['assemblyName'] ?? 'Untitled vessel';
            $mass = $this->models->mass($data);
            $metadata = ['VersionString' => '0.2', 'Name' => $name, 'VehicleName' => $name, 'Description' => $data['AssemblyDefinition']['description'] ?? '',
                'ThumbnailPath' => '', 'Mass' => $mass ?? 0.0, 'Parts' => count($data['parts']), 'Stages' => $data['AssemblyOABConfig']['StagingState']['totalStageCount'] ?? 0,
                'SaveTime' => 0, 'IsAutoSave' => false, 'CampaignName' => '', 'IsBackupWorkspace' => false, 'CriticalConcernLocTerms' => []];
            $contents = '{"Metadata":'.json_encode($metadata, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR).',"ActionType":"None","oabOrientation":"VAB","Assemblies":[{"Assembly":'.$contents.',"isMainAssembly":true,"position":{"x":0,"y":0,"z":0}}]}';
            $notes[] = 'Assembly converted to an OAB workspace.';
            if ($mass !== null) {
                $notes[] = 'Mass estimated from part definitions and stored resources; check it before staging.';
            }
            if ($mass === null) {
                $notes[] = 'Mass is unavailable for one or more parts. Enter the vessel mass before staging.';
            }
            $data = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
        }
        if (empty($data['Assemblies']) || ! is_array($data['Assemblies'])) {
            throw ValidationException::withMessages(['file' => 'Upload an OAB workspace or a serialized assembly JSON file.']);
        }
        foreach ($data['Assemblies'] as $index => &$placed) {
            if (empty($placed['Assembly']) && ! empty($placed['assembly'])) {
                $assembly = $this->normalizer->normalize($placed['assembly'], true);
                $raw = $this->editor->extract($contents, ['/Assemblies/'.$index]);
                $wrapped = $this->editor->patch($raw['/Assemblies/'.$index], ['/assembly' => '']);
                $wrapped = substr($wrapped, 0, -1).',"Assembly":'.$assembly.'}';
                $contents = $this->replaceRaw($contents, '/Assemblies/'.$index, $wrapped);
                $placed['Assembly'] = json_decode($assembly, true, 128, JSON_THROW_ON_ERROR);
                $notes[] = 'Legacy serialized assembly decoded.';
            }
            if (empty($placed['Assembly']['parts'])) {
                throw ValidationException::withMessages(['file' => 'Each workspace assembly must contain serialized parts.']);
            }
            if (count($placed['Assembly']['parts']) > 5000) {
                throw ValidationException::withMessages(['file' => 'The editor supports at most 5,000 parts per assembly.']);
            }
        }
        unset($placed);
        $main = $this->mainIndex($data);
        if ($main !== 0) {
            $paths = array_map(fn ($i) => '/Assemblies/'.$i, array_keys($data['Assemblies']));
            $raw = $this->editor->extract($contents, $paths);
            $order = array_merge([$main], array_values(array_diff(array_keys($data['Assemblies']), [$main])));
            $contents = $this->replaceRaw($contents, '/Assemblies', '['.implode(',', array_map(fn ($i) => $raw['/Assemblies/'.$i], $order)).']');
            $data = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
            $notes[] = 'Main assembly moved first for OTA compatibility.';
        }
        $assembly = $data['Assemblies'][0]['Assembly'];
        $name = $data['Metadata']['VehicleName'] ?? $data['Metadata']['Name'] ?? $assembly['AssemblyDefinition']['assemblyName'] ?? 'Untitled vessel';
        $metadata = $data['Metadata'] ?? [];
        $changes = ['/Assemblies/0/isMainAssembly' => true];
        if (! isset($data['Metadata'])) {
            $changes['/Metadata'] = ['VersionString' => '0.2', 'Name' => $name, 'VehicleName' => $name, 'Mass' => $this->models->mass($assembly) ?? 0.0,
                'Parts' => count($assembly['parts']), 'Stages' => $assembly['AssemblyOABConfig']['StagingState']['totalStageCount'] ?? 0, 'ThumbnailPath' => '', 'CampaignName' => ''];
        } else {
            $changes += ['/Metadata/Name' => $name, '/Metadata/VehicleName' => $name, '/Metadata/Parts' => count($assembly['parts']),
                '/Metadata/ThumbnailPath' => '', '/Metadata/CampaignName' => ''];
        }
        $contents = $this->editor->patch($contents, $changes);
        if (strlen($contents) > config('ota.limits.vessel')) {
            throw ValidationException::withMessages(['file' => 'The converted workspace exceeds the 10 MiB OTA limit.']);
        }

        return $this->response($contents, $notes);
    }

    public function edit(string $contents, array $options): array
    {
        $contents = $this->convert($contents)['contents'];
        $data = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
        $changes = [];
        $requested = $options['deployment'] ?? [];
        $matched = [];
        foreach ($data['Assemblies'] as $ai => $placed) {
            $assembly = $placed['Assembly'];
            $root = collect($assembly['parts'])->first(fn ($p) => $p['PartGuid']['Guid'] === $assembly['AssemblyOABConfig']['RootGuid']['Guid']);
            foreach ($assembly['parts'] as $pi => $part) {
                $guid = $part['PartGuid']['Guid'];
                $prefix = ['Assemblies', $ai, 'Assembly', 'parts', $pi];
                if (isset($options['rotation'])) {
                    $this->rotate($part['partState'], $root['partState']['localPosition'], $prefix, $options['rotation'], $changes);
                }
                foreach ($part['PartModulesState'] ?? [] as $mi => $module) {
                    foreach ($module['ModuleData'] ?? [] as $di => $entry) {
                        if (($entry['Name'] ?? '') !== 'Data_Deployable' || ($entry['DataObject']['UseAnimation'] ?? true) === false) {
                            continue;
                        }
                        if (! array_key_exists('all_deployed', $options) && ! array_key_exists($guid, $requested)) {
                            continue;
                        }
                        $extended = (bool) ($requested[$guid] ?? $options['all_deployed']);
                        $matched[$guid] = true;
                        $base = array_merge($prefix, ['PartModulesState', $mi, 'ModuleData', $di, 'DataObject']);
                        $object = $entry['DataObject'] ?? [];
                        foreach (['CurrentDeployState' => $extended ? 'Extended' : 'Retracted', 'toggleExtend' => $extended,
                            'AnimationNormalizedTime' => $extended ? 1.0 : 0.0, 'DeployLimit' => 1.0] as $key => $value) {
                            $suffix = isset($object[$key]) ? [$key, 'storedValue'] : [$key];
                            $changes[LosslessJsonEditor::pointer(array_merge($base, $suffix))] = isset($object[$key]) ? $value : ['storedValue' => $value];
                        }
                        foreach (['IsExtended' => $extended, 'IsRetracted' => ! $extended, 'IsExtending' => false, 'IsRetracting' => false] as $key => $value) {
                            $changes[LosslessJsonEditor::pointer(array_merge($base, [$key]))] = $value;
                        }
                    }
                }
            }
        }
        if (array_diff(array_keys($requested), array_keys($matched))) {
            throw ValidationException::withMessages(['deployment' => 'A selected part has no deployable module.']);
        }
        if (isset($options['name'])) {
            $changes += ['/Metadata/Name' => $options['name'], '/Metadata/VehicleName' => $options['name']];
            foreach ($data['Assemblies'] as $ai => $placed) {
                $changes['/Assemblies/'.$ai.'/Assembly/AssemblyDefinition/assemblyName'] = $options['name'];
            }
        }
        if (isset($options['mass'])) {
            $changes['/Metadata/Mass'] = (float) $options['mass'];
        }

        return $this->response($this->editor->patch($contents, $changes));
    }

    public function response(string $contents, array $notes = []): array
    {
        $data = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
        $assembly = $data['Assemblies'][$this->mainIndex($data)]['Assembly'];
        $root = collect($assembly['parts'])->first(fn ($p) => ($p['PartGuid']['Guid'] ?? '') === ($assembly['AssemblyOABConfig']['RootGuid']['Guid'] ?? ''));
        if (! $root) {
            throw ValidationException::withMessages(['file' => 'The saved root part is missing.']);
        }
        $parts = [];
        $partIds = [];
        foreach ($assembly['parts'] as $part) {
            if (! is_string($part['partName'] ?? null) || ! is_string($part['PartGuid']['Guid'] ?? null)) {
                throw ValidationException::withMessages(['file' => 'A serialized part is missing its name or GUID.']);
            }
            if (isset($partIds[$part['PartGuid']['Guid']])) {
                throw ValidationException::withMessages(['file' => 'The main assembly contains duplicate part GUIDs.']);
            }
            $partIds[$part['PartGuid']['Guid']] = true;
            foreach (['localPosition' => ['x', 'y', 'z'], 'localRotation' => ['x', 'y', 'z', 'w']] as $field => $components) {
                foreach ($components as $component) {
                    $value = $part['partState'][$field][$component] ?? null;
                    if (! is_numeric($value) || ! is_finite((float) $value)) {
                        throw ValidationException::withMessages(['file' => 'A part has invalid position or rotation data.']);
                    }
                }
            }
            $deployable = null;
            $canDeploy = false;
            $color = [0.55, 0.6, 0.65];
            foreach ($part['PartModulesState'] ?? [] as $module) {
                foreach ($module['ModuleData'] ?? [] as $entry) {
                    $object = $entry['DataObject'] ?? [];
                    if (($entry['Name'] ?? '') === 'Data_Deployable') {
                        $deployable = in_array($object['CurrentDeployState']['storedValue'] ?? 'Retracted', ['Extended', 'Extending'], true);
                        $canDeploy = $object['UseAnimation'] ?? true;
                    }
                    if (($entry['Name'] ?? '') === 'Data_Color' && isset($object['Base']['storedValue'])) {
                        $c = $object['Base']['storedValue'];
                        $color = [$c['r'], $c['g'], $c['b']];
                    }
                }
            }
            $position = $part['partState']['localPosition'];
            $origin = $root['partState']['localPosition'];
            $parts[] = ['guid' => $part['PartGuid']['Guid'], 'name' => $part['partName'],
                'position' => array_map(fn ($k) => $position[$k] - $origin[$k], ['x', 'y', 'z']),
                'rotation' => array_map(fn ($k) => $part['partState']['localRotation'][$k], ['x', 'y', 'z', 'w']),
                'deployed' => $deployable, 'canDeploy' => $canDeploy, 'color' => $color];
        }

        return ['contents' => $contents, 'metadata' => $data['Metadata'], 'parts' => $parts, 'notes' => $notes,
            'assemblies' => count($data['Assemblies']), 'bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)];
    }

    private function mainIndex(array $data): int
    {
        foreach ($data['Assemblies'] as $i => $placed) {
            if ($placed['isMainAssembly'] ?? false) {
                return $i;
            }
        }

        return 0;
    }

    private function replaceRaw(string $contents, string $path, string $replacement): string
    {
        return $this->editor->patch($contents, [$path => new RawJsonValue($replacement)]);
    }

    private function rotate(array $state, array $origin, array $prefix, array $rotation, array &$changes): void
    {
        $axis = $rotation['axis'];
        $angle = deg2rad($rotation['degrees']);
        $vector = ['x' => 0.0, 'y' => 0.0, 'z' => 0.0];
        $vector[$axis] = sin($angle / 2);
        $q = [$vector['x'], $vector['y'], $vector['z'], cos($angle / 2)];
        $multiply = fn ($a, $b) => [
            $a[3] * $b[0] + $a[0] * $b[3] + $a[1] * $b[2] - $a[2] * $b[1],
            $a[3] * $b[1] - $a[0] * $b[2] + $a[1] * $b[3] + $a[2] * $b[0],
            $a[3] * $b[2] + $a[0] * $b[1] - $a[1] * $b[0] + $a[2] * $b[3],
            $a[3] * $b[3] - $a[0] * $b[0] - $a[1] * $b[1] - $a[2] * $b[2],
        ];
        $p = array_map(fn ($k) => $state['localPosition'][$k] - $origin[$k], ['x', 'y', 'z']);
        $v = $multiply($multiply($q, [...$p, 0]), [-$q[0], -$q[1], -$q[2], $q[3]]);
        foreach (['x', 'y', 'z'] as $i => $key) {
            $changes[LosslessJsonEditor::pointer([...$prefix, 'partState', 'localPosition', $key])] = $v[$i] + $origin[$key];
        }
        foreach (['localRotation', 'UserRotation'] as $key) {
            $saved = $state[$key] ?? ['x' => 0, 'y' => 0, 'z' => 0, 'w' => 1];
            $old = array_map(fn ($k) => $saved[$k], ['x', 'y', 'z', 'w']);
            if ((float) array_sum(array_map(fn ($v) => $v * $v, $old)) === 0.0) {
                $old = [0, 0, 0, 1];
            }
            $new = $multiply($q, $old);
            foreach (['x', 'y', 'z', 'w'] as $i => $component) {
                $changes[LosslessJsonEditor::pointer([...$prefix, 'partState', $key, $component])] = $new[$i];
            }
        }
    }
}

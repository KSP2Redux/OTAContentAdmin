<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class GenerateCompatibilityCatalog extends Command
{
    protected $signature = 'ota:catalog:generate {missions : Directory containing the oldest supported bundled mission JSON} {--source-sha= : Exact 40-character Ksp2Redux source commit} {--output= : Output path}';

    protected $description = 'Generate the reviewed OTA authoring compatibility catalog from bundled mission definitions';

    public function handle(): int
    {
        $directory = realpath($this->argument('missions'));
        $sourceSha = (string) $this->option('source-sha');
        if ($directory === false || ! is_dir($directory)) {
            throw new RuntimeException('The mission definition directory does not exist.');
        }
        if (! preg_match('/^[0-9a-f]{40}$/', $sourceSha)) {
            throw new RuntimeException('--source-sha must be an exact lowercase Git commit SHA.');
        }

        $existing = json_decode(file_get_contents(config('ota.compatibility_path')), true, 128, JSON_THROW_ON_ERROR);
        $ids = [];
        $references = [];
        $types = [];
        $paths = [];
        $fields = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'json') {
                continue;
            }
            $data = json_decode(file_get_contents($file->getPathname()), true, 128, JSON_THROW_ON_ERROR);
            if (is_string($data['ID'] ?? null) && str_starts_with($data['ID'], 'KSP2Mission_')) {
                $ids[] = $data['ID'];
            }
            $this->walk($data, [], $types, $paths, $fields, $references, $existing['localization_key_pattern']);
        }
        $ids = [...$ids, ...$references];
        $ids = $this->sortedUnique($ids);
        $types = $this->sortedUnique($types);
        $paths = $this->sortedUnique($paths);
        $fields = $this->sortedUnique($fields);
        $catalog = array_merge($existing, ['source_sha' => $sourceSha, 'mission_types' => $types, 'bundled_mission_ids' => $ids, 'localization_paths' => $paths, 'localization_fields' => $fields]);
        $output = $this->option('output') ?: config('ota.compatibility_path');
        file_put_contents($output, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
        $this->info("Generated catalog for {$sourceSha}: ".count($ids).' missions, '.count($types).' node types.');

        return self::SUCCESS;
    }

    private function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    private function walk(mixed $value, array $path, array &$types, array &$paths, array &$fields, array &$references, string $localizationPattern): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            $segment = is_int($key) ? '[]' : $key;
            $childPath = [...$path, $segment];
            if ($key === '$type' && is_string($child)) {
                $types[] = $child;
            }
            if (in_array($key, ['TargetMissionID', 'nextMissionID', 'parentMissionID'], true) && is_string($child) && str_starts_with($child, 'KSP2Mission_')) {
                $references[] = $child;
            }
            if (is_string($key) && is_string($child) && preg_match($localizationPattern, $child)) {
                $fields[] = $key;
                $paths[] = str_replace('.[]', '[]', implode('.', $childPath));
            }
            $this->walk($child, $childPath, $types, $paths, $fields, $references, $localizationPattern);
        }
    }
}

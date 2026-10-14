<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportVesselModels extends Command
{
    protected $signature = 'vessels:import-models {directory : Private model package directory}';

    protected $description = 'Import generated part geometry into authenticated private model storage';

    public function handle(): int
    {
        $root = realpath($this->argument('directory'));
        if (! $root || ! is_file($root.'/catalog.json')) {
            $this->error('The directory must contain catalog.json and its GLB files.');

            return self::FAILURE;
        }
        $catalog = json_decode(file_get_contents($root.'/catalog.json'), true, 128, JSON_THROW_ON_ERROR);
        if (($catalog['version'] ?? null) !== 1 || ! is_array($catalog['parts'] ?? null)) {
            throw new RuntimeException('Unsupported model catalog.');
        }
        $disk = Storage::disk('vessel-models');
        foreach ($catalog['parts'] as $name => $entry) {
            if (! preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $name) || str_contains($name, '..')) {
                throw new RuntimeException('Unsafe part identifier.');
            }
            foreach (['retracted', 'extended'] as $pose) {
                $file = $entry[$pose] ?? null;
                if ($file === null) {
                    continue;
                }
                $source = realpath($root.'/'.$file);
                if (! preg_match('/\A[a-z0-9][a-z0-9._-]*\.glb\z/', $file) || str_contains($file, '..') || ! $source || dirname($source) !== $root || filesize($source) > 50 * 1024 * 1024) {
                    throw new RuntimeException('Unsafe model path.');
                }
                $contents = file_get_contents($source);
                if (substr($contents, 0, 4) !== 'glTF' || ! hash_equals($entry['hashes'][$pose] ?? '', hash('sha256', $contents))) {
                    throw new RuntimeException('Model checksum or GLB header does not match.');
                }
                $disk->put($file, $contents);
            }
        }
        $disk->put('catalog.json', json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->info('Imported '.count($catalog['parts']).' part definitions and their available private models.');

        return self::SUCCESS;
    }
}

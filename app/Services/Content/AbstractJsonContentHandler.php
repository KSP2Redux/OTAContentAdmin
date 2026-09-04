<?php

namespace App\Services\Content;

use App\Models\ChangeOperation;
use App\Services\Content\Data\ChannelSnapshot;
use App\Services\Content\Data\ContentDiff;
use App\Services\Content\Data\GeneratedManifest;

abstract class AbstractJsonContentHandler implements ContentTypeHandler
{
    public function __construct(protected LosslessJsonNormalizer $normalizer, protected CompatibilityCatalog $catalog) {}

    public function buildManifest(ChannelSnapshot $snapshot): GeneratedManifest
    {
        $files = array_values($snapshot->files);
        usort($files, fn (array $a, array $b) => ($a['order'] ?? PHP_INT_MAX) <=> ($b['order'] ?? PHP_INT_MAX) ?: strcmp($a['path'], $b['path']));
        foreach ($files as $index => &$file) {
            $file['order'] = $index + 1;
        }
        unset($file);

        return new GeneratedManifest(json_encode(['files' => $files], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $files);
    }

    public function renderDiff(ChangeOperation $operation): ContentDiff
    {
        return new ContentDiff(ucfirst($operation->action).' '.$operation->path, ['channel' => $operation->channel, 'metadata' => $operation->metadata]);
    }

    protected function decoded(string $contents): array
    {
        return json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
    }

    protected function safePath(string $path): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9._-]*\.json\z/', $path) === 1 && ! str_contains($path, '..');
    }
}

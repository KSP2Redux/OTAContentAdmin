<?php

namespace App\Services\Content\Data;

final readonly class NormalizedArtifact
{
    public function __construct(public string $path, public string $contents, public string $sha256, public int $bytes, public array $metadata = []) {}
}

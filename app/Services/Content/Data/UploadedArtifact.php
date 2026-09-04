<?php

namespace App\Services\Content\Data;

final readonly class UploadedArtifact
{
    public function __construct(public string $path, public string $contents, public array $metadata = []) {}
}

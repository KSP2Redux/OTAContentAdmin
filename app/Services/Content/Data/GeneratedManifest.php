<?php

namespace App\Services\Content\Data;

final readonly class GeneratedManifest
{
    public function __construct(public string $contents, public array $files) {}
}

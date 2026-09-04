<?php

namespace App\Services\Content\Data;

final readonly class ContentDiff
{
    public function __construct(public string $summary, public array $details = []) {}
}

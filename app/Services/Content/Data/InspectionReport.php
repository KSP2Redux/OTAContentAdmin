<?php

namespace App\Services\Content\Data;

final readonly class InspectionReport
{
    public function __construct(public array $metadata, public array $warnings = []) {}
}

<?php

namespace App\Services\Content\Data;

final readonly class ValidationReport
{
    public function __construct(public array $errors = [], public array $warnings = []) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function toArray(): array
    {
        return ['passes' => $this->passes(), 'errors' => $this->errors, 'warnings' => $this->warnings];
    }
}

<?php

namespace App\Services\Vessels;

use JsonException;

final readonly class RawJsonValue
{
    public function __construct(public string $contents)
    {
        if (! json_validate($contents, 128)) {
            throw new JsonException('Invalid JSON fragment.');
        }
    }
}

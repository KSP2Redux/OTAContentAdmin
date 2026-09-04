<?php

namespace App\Services\Content;

use InvalidArgumentException;

final readonly class ContentHandlerRegistry
{
    public function __construct(private array $handlers) {}

    public function for(string $channel): ContentTypeHandler
    {
        return $this->handlers[$channel] ?? throw new InvalidArgumentException("Unsupported OTA channel: {$channel}");
    }

    public function channels(): array
    {
        return array_keys($this->handlers);
    }
}

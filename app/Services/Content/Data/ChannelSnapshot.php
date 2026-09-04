<?php

namespace App\Services\Content\Data;

final readonly class ChannelSnapshot
{
    public function __construct(public string $channel, public array $files) {}
}

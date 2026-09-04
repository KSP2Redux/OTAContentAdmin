<?php

namespace Tests\Unit;

use App\Support\SecretRedactor;
use RuntimeException;
use Tests\TestCase;

class SecretRedactorTest extends TestCase
{
    public function test_configured_credentials_are_removed_from_a_persisted_error(): void
    {
        config(['ota.weblate.token' => 'sensitive-token']);

        $message = SecretRedactor::message(new RuntimeException('Request failed with sensitive-token'));

        $this->assertSame('Request failed with [redacted]', $message);
    }
}

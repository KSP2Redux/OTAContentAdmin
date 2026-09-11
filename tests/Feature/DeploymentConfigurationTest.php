<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentConfigurationTest extends TestCase
{
    public function test_private_storage_is_accessible_to_both_web_and_worker_processes(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));

        $this->assertStringContainsString('chown -R 0:33 /uploads /payloads', $compose);
        $this->assertStringContainsString('chmod 0770 /uploads /payloads', $compose);
        $this->assertStringContainsString('ota_uploads:/var/www/html/storage/app/ota-private', $compose);
        $this->assertStringContainsString('ota_payloads:/var/www/html/storage/app/ota-payloads', $compose);
    }
}

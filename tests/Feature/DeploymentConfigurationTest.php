<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentConfigurationTest extends TestCase
{
    public function test_private_storage_is_accessible_to_both_web_and_worker_processes(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));

        $this->assertStringContainsString('chgrp -R 33 /uploads /payloads', $compose);
        $this->assertStringContainsString('chmod -R g+rwX,o-rwx /uploads /payloads', $compose);
        $this->assertStringContainsString('chmod 2770 /uploads /payloads', $compose);
        $this->assertSame(2, substr_count($compose, '    user: "0:33"'));
        $this->assertStringContainsString('ota_uploads:/var/www/html/storage/app/ota-private', $compose);
        $this->assertStringContainsString('ota_payloads:/var/www/html/storage/app/ota-payloads', $compose);
    }
}

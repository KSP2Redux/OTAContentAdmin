<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_redirects_anonymous_users_to_oidc_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_admin_redirect_uses_forwarded_https_scheme(): void
    {
        $response = $this->withHeader('X-Forwarded-Proto', 'https')->get('/admin');

        $this->assertStringStartsWith('https://', $response->headers->get('Location'));
    }

    public function test_admin_rejects_a_user_without_the_publisher_group(): void
    {
        $user = User::factory()->create(['groups' => ['another-group']]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_publisher_can_open_the_admin_dashboard(): void
    {
        Http::fake();
        $user = User::factory()->create(['groups' => [config('ota.auth.publisher_group')]]);

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_custom_content_tables_render_with_consistent_structure_and_empty_states(): void
    {
        Http::fake([
            rtrim(config('ota.content.raw_url'), '/').'/*' => Http::response(['files' => []]),
        ]);
        $user = User::factory()->create(['groups' => [config('ota.auth.publisher_group')]]);

        $this->actingAs($user)
            ->get('/admin/vessels')
            ->assertOk()
            ->assertSee('aria-label="Published main-menu vessels"', escape: false)
            ->assertSee('class="ota-table-shell"', escape: false)
            ->assertSee('class="ota-table-empty"', escape: false)
            ->assertSee('No vessels could be loaded.');

        $this->actingAs($user)
            ->get('/admin/missions')
            ->assertOk()
            ->assertSee('aria-label="Published OTA missions"', escape: false)
            ->assertSee('class="ota-table-shell"', escape: false)
            ->assertSee('class="ota-table-empty"', escape: false)
            ->assertSee('No OTA missions are currently published.');
    }

    public function test_liveness_does_not_require_a_session_or_database_query(): void
    {
        $this->get('/health/live')->assertOk()->assertJson(['status' => 'ok']);
    }
}

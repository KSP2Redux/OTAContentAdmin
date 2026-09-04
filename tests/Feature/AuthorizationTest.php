<?php

namespace Tests\Feature;

use App\Filament\Widgets\ContentChannelsOverview;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
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

    public function test_dashboard_summarizes_each_published_content_channel(): void
    {
        config()->set('ota.weblate.token', 'test-token');
        $rawUrl = rtrim(config('ota.content.raw_url'), '/');
        $weblateUrl = rtrim(config('ota.weblate.url'), '/');
        Http::fake([
            "{$rawUrl}/localizations/manifest.json" => Http::response(['files' => [[], []]]),
            "{$rawUrl}/main-menu-vessels/manifest.json" => Http::response(['files' => [[]]]),
            "{$rawUrl}/missions/manifest.json" => Http::response(['files' => [[], [], []]]),
            "{$weblateUrl}/projects/*/repository/" => Http::response([
                'needs_commit' => false,
                'needs_push' => false,
                'needs_merge' => false,
            ]),
            '*' => Http::response(['object' => ['sha' => str_repeat('a', 40)]]),
        ]);
        $user = User::factory()->create(['groups' => [config('ota.auth.publisher_group')]]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(ContentChannelsOverview::class)
            ->assertSee('Published content')
            ->assertSee('Localizations')
            ->assertSee('Weblate repository is up to date')
            ->assertSee('Main-menu vessels')
            ->assertSee('Published vessels · loaded on startup')
            ->assertSee('Missions')
            ->assertSee('Published OTA missions · loaded with campaigns');
    }

    public function test_system_status_uses_labeled_summaries_instead_of_raw_json(): void
    {
        Cache::put('github-app-token', 'test-github-token');
        config()->set('ota.gitlab.token', 'test-token');
        $contentSha = str_repeat('b', 40);
        $gitlabUrl = rtrim(config('ota.gitlab.url'), '/');
        Http::fake([
            'https://api.github.com/repos/*' => Http::response(['object' => ['sha' => $contentSha]]),
            "{$gitlabUrl}/projects/*/pipelines*" => Http::response([[
                'id' => 42,
                'status' => 'success',
                'ref' => 'develop',
                'updated_at' => '2026-09-05T00:00:00Z',
                'web_url' => 'https://git.example.test/pipelines/42',
            ]]),
        ]);
        $user = User::factory()->create(['groups' => [config('ota.auth.publisher_group')]]);

        $this->actingAs($user)
            ->get('/admin/system-status')
            ->assertOk()
            ->assertSee('Content repository')
            ->assertSee(substr($contentSha, 0, 12))
            ->assertSee('Publication pipeline')
            ->assertSee('Pipeline')
            ->assertSee('#42')
            ->assertSee('Authoring compatibility')
            ->assertSee('Catalog loaded')
            ->assertSee('Supported bodies')
            ->assertDontSee('<pre', escape: false)
            ->assertDontSee('source_sha');
    }

    public function test_liveness_does_not_require_a_session_or_database_query(): void
    {
        $this->get('/health/live')->assertOk()->assertJson(['status' => 'ok']);
    }

    public function test_readiness_uses_the_authenticated_github_app_connection(): void
    {
        Cache::put('github-app-token', 'test-github-token');
        Redis::shouldReceive('connection->command')->once()->with('ping')->andReturn('PONG');
        config()->set('ota.weblate.token', 'test-weblate-token');
        config()->set('ota.gitlab.token', 'test-gitlab-token');

        Http::fake([
            'https://api.github.com/repos/*' => Http::response(['object' => ['sha' => str_repeat('c', 40)]]),
            '*' => Http::response([]),
        ]);

        $this->get('/health/ready')
            ->assertOk()
            ->assertJsonPath('checks.github', 'ok');

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.github.com/repos/')
            && $request->hasHeader('Authorization', 'Bearer test-github-token'));
    }
}

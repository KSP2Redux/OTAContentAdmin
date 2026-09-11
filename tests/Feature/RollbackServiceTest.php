<?php

namespace Tests\Feature;

use App\Models\ChangeOperation;
use App\Models\ChangeSet;
use App\Models\User;
use App\Services\Publishing\RollbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RollbackServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_the_current_manifest_at_the_authenticated_head_when_creating_an_inverse(): void
    {
        Cache::put('github-app-token', 'test-token');
        $entry = ['path' => 'test.json', 'sha256' => 'candidate-hash', 'bytes' => 10, 'order' => 1, 'author' => 'Tester', 'body' => 'Kerbin'];
        $requests = [];
        Http::fake(function ($request) use ($entry, &$requests) {
            $requests[] = $request->url();

            if (str_ends_with($request->url(), '/git/ref/heads/main')) {
                return Http::response(['object' => ['sha' => 'current-head']]);
            }

            $ref = $request->data()['ref'] ?? null;
            $manifest = $ref === 'base-head' ? ['files' => []] : ['files' => [$entry]];

            return Http::response([
                'encoding' => 'base64',
                'content' => base64_encode(json_encode($manifest, JSON_THROW_ON_ERROR)),
            ]);
        });

        $user = User::factory()->create();
        $published = ChangeSet::create([
            'user_id' => $user->id,
            'summary' => 'Production publish test',
            'state' => 'published',
            'base_content_sha' => 'base-head',
            'published_sha' => 'published-head',
        ]);
        ChangeOperation::create([
            'change_set_id' => $published->id,
            'channel' => 'main-menu-vessels',
            'action' => 'add',
            'path' => 'test.json',
            'original_sha' => null,
            'payload_path' => 'unused.json',
            'metadata' => ['author' => 'Tester', 'body' => 'Kerbin'],
            'order_position' => 1,
        ]);

        $inverse = app(RollbackService::class)->createInverse($published, $user->id);
        $operation = $inverse->operations()->sole();

        $this->assertSame('current-head', $inverse->base_content_sha);
        $this->assertSame('delete', $operation->action);
        $this->assertSame(
            hash('sha256', json_encode(['files' => [$entry]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            $operation->metadata['_base_manifest_sha256'],
        );
        $this->assertContains(
            'https://api.github.com/repos/KSP2Redux/Content/contents/main-menu-vessels/manifest.json?ref=current-head',
            $requests,
        );
        $this->assertNotContains(
            'https://raw.githubusercontent.com/KSP2Redux/Content/main/main-menu-vessels/manifest.json',
            $requests,
        );
    }
}

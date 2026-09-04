<?php

namespace App\Http\Controllers;

use App\Services\Integrations\GitHubContentRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        $checks = [];
        $checks['database'] = $this->check(fn () => DB::select('select 1'));
        $checks['redis'] = $this->check(fn () => Redis::connection()->command('ping'));
        $checks['authentik'] = $this->check(fn () => Http::timeout(5)->get(rtrim(config('services.openidconnect.base_url'), '/').'/.well-known/openid-configuration')->throw());
        $checks['github'] = $this->check(fn () => app(GitHubContentRepository::class)->headSha(true));
        $checks['weblate'] = $this->check(fn () => Http::timeout(5)->withToken(config('ota.weblate.token'))->get(rtrim(config('ota.weblate.url'), '/').'/projects/'.config('ota.weblate.project').'/')->throw());
        $checks['gitlab'] = $this->check(fn () => Http::timeout(5)->withHeaders(['PRIVATE-TOKEN' => config('ota.gitlab.token')])->get(rtrim(config('ota.gitlab.url'), '/').'/version')->throw());
        $ready = ! in_array('failed', $checks, true);

        return response()->json(['status' => $ready ? 'ok' : 'unavailable', 'checks' => $checks], $ready ? 200 : 503);
    }

    private function check(callable $callback): string
    {
        try {
            $callback();

            return 'ok';
        } catch (Throwable) {
            return 'failed';
        }
    }
}

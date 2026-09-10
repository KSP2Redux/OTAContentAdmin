<?php

namespace App\Http\Controllers;

use App\Services\Integrations\GitHubContentRepository;
use Illuminate\Http\Response;

class ContentDownloadController extends Controller
{
    public function __invoke(string $channel, string $path, GitHubContentRepository $repository): Response
    {
        abort_unless(in_array($channel, ['main-menu-vessels', 'missions'], true), 404);
        abort_unless((bool) preg_match('/\A[a-z0-9][a-z0-9.-]*\.json\z/', $path), 404);

        $entry = collect($repository->manifest($channel)['files'] ?? [])
            ->firstWhere('path', $path);

        abort_if($entry === null, 404);

        $contents = $repository->file($channel, $path);
        $expectedBytes = $entry['bytes'] ?? null;
        $expectedHash = strtolower($entry['sha256'] ?? '');

        abort_unless(
            is_int($expectedBytes)
            && $expectedBytes === strlen($contents)
            && preg_match('/\A[a-f0-9]{64}\z/', $expectedHash)
            && hash_equals($expectedHash, hash('sha256', $contents)),
            502,
            'The published file does not match its manifest.',
        );

        return response($contents, 200, [
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'attachment; filename="'.$path.'"',
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

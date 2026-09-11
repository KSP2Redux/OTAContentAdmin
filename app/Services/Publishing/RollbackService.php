<?php

namespace App\Services\Publishing;

use App\Models\ChangeOperation;
use App\Models\ChangeSet;
use App\Services\Integrations\GitHubContentRepository;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class RollbackService
{
    public function __construct(private GitHubContentRepository $github, private PayloadStorage $payloads) {}

    public function createInverse(ChangeSet $published, int $actorId): ChangeSet
    {
        if ($published->state !== 'published' || ! $published->published_sha || ! $published->base_content_sha) {
            throw new RuntimeException('Only a successfully published change set can be rolled back.');
        }

        $published->load('operations');
        $currentHead = $this->github->headSha(true);
        $inverse = ChangeSet::create([
            'user_id' => $actorId,
            'base_content_sha' => $currentHead,
            'state' => 'draft',
            'summary' => "Rollback: {$published->summary}",
        ]);

        try {
            foreach ($published->operations as $operation) {
                $currentManifest = $this->github->manifestAtRef($operation->channel, $currentHead);
                $publishedManifest = $this->github->manifestAtRef($operation->channel, $published->published_sha);
                $baseManifest = $this->github->manifestAtRef($operation->channel, $published->base_content_sha);
                $current = collect($currentManifest['files'] ?? [])->firstWhere('path', $operation->path);
                $atPublished = collect($publishedManifest['files'] ?? [])->firstWhere('path', $operation->path);
                $before = collect($baseManifest['files'] ?? [])->firstWhere('path', $operation->path);

                if (($current['sha256'] ?? null) !== ($atPublished['sha256'] ?? null)) {
                    throw new RuntimeException("{$operation->channel}/{$operation->path} changed after the publication and cannot be safely rolled back.");
                }

                $action = match ($operation->action) {
                    'add' => 'delete',
                    'delete' => 'add',
                    'replace' => 'replace',
                    'reorder' => 'reorder',
                    default => throw new RuntimeException("Unsupported rollback action {$operation->action}."),
                };
                $payloadPath = null;
                if (in_array($action, ['add', 'replace'], true)) {
                    $payloadPath = 'drafts/rollbacks/'.$inverse->id.'/'.Str::uuid().'.json';
                    $this->payloads->put($payloadPath, $this->github->fileAtRef($operation->channel, $operation->path, $published->base_content_sha));
                }
                $metadata = $before ? array_merge($operation->metadata ?? [], array_intersect_key($before, array_flip(['author', 'body']))) : ($operation->metadata ?? []);
                ChangeOperation::create([
                    'change_set_id' => $inverse->id,
                    'channel' => $operation->channel,
                    'action' => $action,
                    'path' => $operation->path,
                    'original_sha' => $current['sha256'] ?? null,
                    'payload_path' => $payloadPath,
                    'metadata' => $metadata,
                    'order_position' => $before['order'] ?? $operation->order_position,
                ]);
            }
        } catch (\Throwable $exception) {
            $inverse->delete();
            throw $exception;
        }

        return $inverse;
    }
}

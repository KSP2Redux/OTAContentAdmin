<?php

namespace App\Services\Publishing;

use App\Models\ChangeSet;
use App\Services\Content\ContentHandlerRegistry;
use App\Services\Content\Data\ChangeSetContext;
use App\Services\Content\Data\ChannelSnapshot;
use App\Services\Content\Data\UploadedArtifact;
use App\Services\Integrations\GitHubContentRepository;
use App\Services\Integrations\WeblateClient;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final readonly class ContentPublisher
{
    public function __construct(private GitHubContentRepository $github, private ContentHandlerRegistry $handlers, private WeblateClient $weblate) {}

    public function publish(ChangeSet $changeSet, int $unrelatedHeadRetries = 1): string
    {
        $changeSet->load('operations', 'user');
        if ($changeSet->operations->isEmpty()) {
            throw new RuntimeException('Change set has no operations.');
        }
        $head = $this->github->headSha(true);
        $changes = [];
        $reports = [];
        $candidateIds = $this->candidateMissionIds($changeSet);

        foreach ($changeSet->operations->groupBy('channel') as $channel => $operations) {
            $handler = $this->handlers->for($channel);
            $manifest = $this->github->manifest($channel);
            $manifestHash = hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $entries = collect($manifest['files'] ?? [])->keyBy('path');

            foreach ($operations as $operation) {
                if (($operation->metadata['_base_manifest_sha256'] ?? $manifestHash) !== $manifestHash) {
                    throw new RuntimeException("{$channel}/manifest.json changed since this operation was staged.");
                }
                $live = $entries->get($operation->path);
                if ($operation->action === 'add' && $live) {
                    throw new RuntimeException("{$channel}/{$operation->path} already exists.");
                }
                if (in_array($operation->action, ['replace', 'delete', 'reorder'], true) && ! $live) {
                    throw new RuntimeException("{$channel}/{$operation->path} no longer exists.");
                }
                if ($operation->original_sha && $live && ! hash_equals($operation->original_sha, $live['sha256'] ?? '')) {
                    throw new RuntimeException("{$channel}/{$operation->path} changed since it was staged.");
                }

                if ($operation->action === 'delete') {
                    $entries->forget($operation->path);
                    $changes["{$channel}/{$operation->path}"] = null;

                    continue;
                }

                if ($operation->action === 'reorder') {
                    $live['order'] = $operation->order_position;
                    $entries->put($operation->path, $live);

                    continue;
                }

                $contents = Storage::disk('ota-private')->get($operation->payload_path);
                $artifact = new UploadedArtifact($operation->path, $contents, $operation->metadata ?? []);
                $report = $handler->validate(new ChangeSetContext($artifact, $manifest, $candidateIds));
                $reports["{$channel}/{$operation->path}"] = $report->toArray();
                if (! $report->passes()) {
                    throw new RuntimeException(implode(' ', $report->errors));
                }
                $normalized = $handler->normalize($artifact);
                $normalizedReport = $handler->validate(new ChangeSetContext(new UploadedArtifact($normalized->path, $normalized->contents, $normalized->metadata), $manifest, $candidateIds));
                if (! $normalizedReport->passes()) {
                    throw new RuntimeException('Normalized artifact failed validation: '.implode(' ', $normalizedReport->errors));
                }
                $entry = array_merge($live ?? [], ['path' => $operation->path, 'sha256' => $normalized->sha256, 'bytes' => $normalized->bytes, 'order' => $operation->order_position ?? ($live['order'] ?? $entries->count() + 1)]);
                if ($channel === 'main-menu-vessels') {
                    $entry += ['author' => $operation->metadata['author'], 'body' => $operation->metadata['body']];
                }
                $entries->put($operation->path, $entry);
                $changes["{$channel}/{$operation->path}"] = $normalized->contents;

                if ($channel === 'missions') {
                    $sources = array_filter((array) ($operation->metadata['localization_sources'] ?? []), fn ($value) => is_string($value) && trim($value) !== '');
                    $inspection = $handler->inspect($artifact);
                    $missing = $this->weblate->missingMissionSourceKeys($inspection->metadata['localization_keys'] ?? []);
                    $unresolved = array_values(array_diff($missing, array_keys($sources)));
                    if ($unresolved) {
                        throw new RuntimeException('Missing English source text for: '.implode(', ', $unresolved));
                    }
                    if ($missing) {
                        throw new RuntimeException('Mission localization sources were not verified in Weblate after publication: '.implode(', ', $missing));
                    }
                }
            }

            $generated = $handler->buildManifest(new ChannelSnapshot($channel, $entries->values()->all()));
            $changes["{$channel}/manifest.json"] = $generated->contents;
        }

        $changeSet->update(['state' => 'publishing', 'base_content_sha' => $head, 'validation_report' => $reports]);
        try {
            $sha = $this->github->commit($changes, "OTA: {$changeSet->summary} [{$changeSet->id}]\n\nPublished by {$changeSet->user?->name}", $head);
        } catch (RuntimeException $exception) {
            if ($unrelatedHeadRetries > 0 && str_contains($exception->getMessage(), 'moved before publication')) {
                return $this->publish($changeSet, $unrelatedHeadRetries - 1);
            }
            throw $exception;
        }
        $changeSet->update(['state' => 'published', 'published_sha' => $sha]);

        return $sha;
    }

    private function candidateMissionIds(ChangeSet $changeSet): array
    {
        $ids = [];
        $changedPaths = $changeSet->operations->where('channel', 'missions')->pluck('path')->all();
        $handler = $this->handlers->for('missions');
        foreach ($this->github->manifest('missions')['files'] ?? [] as $entry) {
            if (in_array($entry['path'], $changedPaths, true)) {
                continue;
            }
            $artifact = new UploadedArtifact($entry['path'], $this->github->file('missions', $entry['path']), $entry);
            $id = $handler->inspect($artifact)->metadata['id'] ?? null;
            if (is_string($id)) {
                $ids[] = $id;
            }
        }
        foreach ($changeSet->operations->where('channel', 'missions')->whereNotIn('action', ['delete']) as $operation) {
            if (! $operation->payload_path) {
                continue;
            }
            $data = json_decode(Storage::disk('ota-private')->get($operation->payload_path), true);
            if (is_string($data['ID'] ?? null)) {
                $ids[] = $data['ID'];
            }
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Mission IDs must be unique across candidate and existing OTA missions.');
        }

        return $ids;
    }
}

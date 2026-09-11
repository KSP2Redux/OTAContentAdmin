<?php

namespace App\Services\Publishing;

use App\Models\ChangeSet;
use App\Services\Content\ContentHandlerRegistry;
use App\Services\Content\Data\ChangeSetContext;
use App\Services\Content\Data\UploadedArtifact;
use App\Services\Integrations\GitHubContentRepository;
use App\Services\Integrations\WeblateClient;

final readonly class ChangeSetValidator
{
    public function __construct(
        private GitHubContentRepository $github,
        private ContentHandlerRegistry $handlers,
        private WeblateClient $weblate,
        private PayloadStorage $payloads,
    ) {}

    /** @return array{valid: bool, errors: array<string>, warnings: array<string>, operations: array<string, mixed>} */
    public function validate(ChangeSet $changeSet): array
    {
        $changeSet->load('operations');
        $errors = [];
        $warnings = [];
        $reports = [];
        $totalBytes = 0;
        [$candidateMissionIds, $duplicateMissionIds] = $this->missionIds($changeSet);
        if ($duplicateMissionIds) {
            $errors[] = 'Mission IDs must be unique across candidate and existing OTA missions.';
        }

        if ($changeSet->operations->isEmpty()) {
            $errors[] = 'The change set has no operations.';
        }
        foreach ($changeSet->operations->groupBy('channel') as $channel => $operations) {
            $manifest = $this->github->manifest($channel);
            $manifestHash = hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $entries = collect($manifest['files'] ?? [])->keyBy('path');
            foreach ($operations as $operation) {
                if (($operation->metadata['_base_manifest_sha256'] ?? $manifestHash) !== $manifestHash) {
                    $errors[] = "{$channel}/manifest.json changed since this operation was staged.";
                }
                $live = $entries->get($operation->path);
                if ($operation->action === 'add' && $live) {
                    $errors[] = "{$channel}/{$operation->path} already exists.";
                }
                if (in_array($operation->action, ['replace', 'delete', 'reorder'], true) && ! $live) {
                    $errors[] = "{$channel}/{$operation->path} no longer exists.";
                }
                if ($operation->original_sha && $live && ! hash_equals($operation->original_sha, $live['sha256'] ?? '')) {
                    $errors[] = "{$channel}/{$operation->path} changed since it was staged.";
                }
                if (in_array($operation->action, ['delete', 'reorder'], true)) {
                    continue;
                }

                if (! is_string($operation->payload_path) || ! $this->payloads->exists($operation->payload_path)) {
                    $errors[] = "{$channel}/{$operation->path} no longer has its staged upload. Remove this change and add it again.";

                    continue;
                }

                $contents = $this->payloads->get($operation->payload_path);
                $totalBytes += strlen($contents);
                $artifact = new UploadedArtifact($operation->path, $contents, $operation->metadata ?? []);
                $handler = $this->handlers->for($channel);
                $report = $handler->validate(new ChangeSetContext($artifact, $manifest, $candidateMissionIds));
                $reports["{$channel}/{$operation->path}"] = $report->toArray();
                array_push($errors, ...$report->errors);
                array_push($warnings, ...$report->warnings);
                if ($report->passes()) {
                    $normalized = $handler->normalize($artifact);
                    $normalizedReport = $handler->validate(new ChangeSetContext(new UploadedArtifact($normalized->path, $normalized->contents, $normalized->metadata), $manifest, $candidateMissionIds));
                    array_push($errors, ...$normalizedReport->errors);
                    array_push($warnings, ...$normalizedReport->warnings);
                }

                if ($channel === 'missions' && $report->passes()) {
                    $keys = $handler->inspect($artifact)->metadata['localization_keys'] ?? [];
                    $missing = $this->weblate->missingMissionSourceKeys($keys);
                    $sources = array_filter((array) ($operation->metadata['localization_sources'] ?? []), fn ($source) => is_string($source) && trim($source) !== '');
                    foreach (array_diff($missing, array_keys($sources)) as $key) {
                        $errors[] = "Missing English source text for localization key {$key}.";
                    }
                }
            }
        }
        if ($totalBytes > config('ota.limits.change_set')) {
            $errors[] = 'Candidate payloads exceed the 50 MiB change-set limit.';
        }

        $result = ['valid' => $errors === [], 'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings)), 'operations' => $reports];
        $changeSet->update(['validation_report' => $result, 'state' => $result['valid'] ? 'validated' : 'draft']);

        return $result;
    }

    private function missionIds(ChangeSet $changeSet): array
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
        foreach ($changeSet->operations->where('channel', 'missions')->whereNotIn('action', ['delete', 'reorder']) as $operation) {
            if (! is_string($operation->payload_path) || ! $this->payloads->exists($operation->payload_path)) {
                continue;
            }

            $data = json_decode($this->payloads->get($operation->payload_path), true);
            if (is_string($data['ID'] ?? null)) {
                $ids[] = $data['ID'];
            }
        }

        return [$ids, count($ids) !== count(array_unique($ids))];
    }
}

<?php

namespace App\Services\Publishing;

use App\Models\ChangeOperation;
use App\Models\ChangeSet;
use App\Services\Content\ContentHandlerRegistry;
use App\Services\Content\Data\UploadedArtifact;
use App\Services\Integrations\GitHubContentRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class OperationStager
{
    public function __construct(private GitHubContentRepository $github, private ContentHandlerRegistry $handlers) {}

    public function stage(string $channel, string $action, array $data): ChangeOperation
    {
        $changeSet = ChangeSet::query()->whereKey($data['change_set_id'])->whereIn('state', ['draft', 'validated'])->firstOrFail();
        $manifest = $this->github->manifest($channel);
        $path = $data['path'] ?? null;
        $metadata = $data['metadata'] ?? [];
        $uploadedPayloadPath = null;
        $ownedPayloadPath = null;
        $contents = null;
        $metadata['_base_manifest_sha256'] = hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (! in_array($action, ['delete', 'reorder'], true)) {
            $uploadedPayloadPath = $data['upload'];
            $expectedDirectory = $channel === 'main-menu-vessels' ? 'drafts/vessels/' : 'drafts/missions/';
            if (! is_string($uploadedPayloadPath) || ! str_starts_with($uploadedPayloadPath, $expectedDirectory) || str_contains($uploadedPayloadPath, '..') || str_contains($uploadedPayloadPath, '\\') || ! Storage::disk('ota-private')->exists($uploadedPayloadPath)) {
                throw new RuntimeException('The uploaded payload path is invalid. Upload the file again.');
            }
            $contents = Storage::disk('ota-private')->get($uploadedPayloadPath);
            if ($channel === 'missions' && ! $path) {
                $id = json_decode($contents, true, 128, JSON_THROW_ON_ERROR)['ID'] ?? throw new RuntimeException('Mission ID is required.');
                $path = Str::slug($id).'.json';
            }
            if ($channel === 'main-menu-vessels' && ! $path) {
                $path = Str::slug($data['slug']).'.json';
            }
            $inspection = $this->handlers->for($channel)->inspect(new UploadedArtifact($path, $contents, $metadata));
            $metadata['inspection'] = $inspection->metadata;
            $ownedPayloadPath = 'drafts/change-sets/'.$changeSet->id.'/'.$channel.'/'.Str::uuid().'.json';
            $payloadPath = $ownedPayloadPath;
        } else {
            $payloadPath = null;
        }
        if (! is_string($path) || preg_match('/\A[a-z0-9][a-z0-9._-]*\.json\z/', $path) !== 1 || str_contains($path, '..')) {
            throw new RuntimeException('Filename must be a safe lowercase JSON slug.');
        }
        $live = collect($manifest['files'] ?? [])->first(fn (array $entry) => strtolower($entry['path']) === strtolower($path));
        if ($action === 'add' && $live) {
            throw new RuntimeException('That path already exists. Use Replace.');
        }
        if (in_array($action, ['replace', 'delete', 'reorder'], true) && ! $live) {
            throw new RuntimeException('The selected item no longer exists.');
        }
        if ($ownedPayloadPath !== null && $contents !== null) {
            Storage::disk('ota-private')->put($ownedPayloadPath, $contents);
        }
        $existingOperation = ChangeOperation::query()
            ->where('change_set_id', $changeSet->id)
            ->where('channel', $channel)
            ->where('path', $path)
            ->first();
        $replacedPayloadPath = $existingOperation?->payload_path;

        try {
            $operation = DB::transaction(function () use ($changeSet, $channel, $path, $action, $live, $payloadPath, $metadata, $data): ChangeOperation {
                $changeSet->update(['state' => 'draft', 'validation_report' => null]);

                return ChangeOperation::query()->updateOrCreate(
                    ['change_set_id' => $changeSet->id, 'channel' => $channel, 'path' => $path],
                    ['action' => $action, 'original_sha' => $live['sha256'] ?? null, 'payload_path' => $payloadPath, 'metadata' => $metadata, 'order_position' => $data['order_position'] ?? ($live['order'] ?? null)],
                );
            });
        } catch (Throwable $exception) {
            $this->discardPayload($ownedPayloadPath);

            throw $exception;
        }

        $this->discardPayload($uploadedPayloadPath);
        if ($replacedPayloadPath !== $payloadPath && ! ChangeOperation::query()->where('payload_path', $replacedPayloadPath)->exists()) {
            $this->discardPayload($replacedPayloadPath);
        }

        return $operation;
    }

    private function discardPayload(?string $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        try {
            Storage::disk('ota-private')->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

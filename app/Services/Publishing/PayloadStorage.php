<?php

namespace App\Services\Publishing;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class PayloadStorage
{
    public function put(string $path, string $contents): void
    {
        $this->primary()->put($path, $contents);
    }

    public function exists(?string $path): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }

        return $this->primary()->exists($path) || $this->legacy()->exists($path);
    }

    public function get(string $path): string
    {
        if ($this->primary()->exists($path)) {
            return $this->primary()->get($path);
        }

        if ($this->legacy()->exists($path)) {
            return $this->legacy()->get($path);
        }

        throw new RuntimeException("Unable to read staged payload {$path}.");
    }

    public function delete(?string $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        $this->primary()->delete($path);
        $this->legacy()->delete($path);
    }

    public function pruneOlderThan(int $timestamp): void
    {
        $this->pruneDisk($this->primary(), '', $timestamp);
        $this->pruneDisk($this->legacy(), 'drafts', $timestamp);
    }

    private function pruneDisk(FilesystemAdapter $disk, string $directory, int $timestamp): void
    {
        foreach ($disk->allFiles($directory) as $file) {
            if ($disk->lastModified($file) < $timestamp) {
                $disk->delete($file);
            }
        }
    }

    private function primary(): FilesystemAdapter
    {
        return Storage::disk(config('ota.storage.payload_disk'));
    }

    private function legacy(): FilesystemAdapter
    {
        return Storage::disk(config('ota.storage.legacy_payload_disk'));
    }
}
